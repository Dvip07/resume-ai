<?php

namespace Tests\Unit\Services\Tailoring;

use App\Enums\CoverLetterDetectionMethod;
use App\Enums\CoverLetterRequirement;
use App\Exceptions\ModelRouterException;
use App\Models\JobListing;
use App\Services\ModelCompletionResult;
use App\Services\ModelRouterService;
use App\Services\ModelTierContext;
use App\Services\Tailoring\CoverLetterRequirementDetector;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;
use Throwable;

/**
 * Task 12.2: does this posting ask for a cover letter (Requirements 7.1, 7.2)?
 *
 * Two things are under test and they are not the same thing. One is the answer.
 * The other is *what the answer cost*: Requirement 7.2 exists to stop the
 * pipeline spending model calls on postings that never asked for a letter, so
 * almost every test here also asserts on `$this->router->calls` — a correct
 * answer reached by paying a model to read the word "optional" is a failure of
 * the requirement even though the label is right.
 *
 * The router is a recording subclass rather than an HTTP fake, for the same
 * reason as {@see ResumeTailoringServiceTest}: what matters is the detector's own
 * behaviour — when it escalates, what it asks for, what it refuses to believe —
 * none of which should depend on OpenRouter's wire format. Nothing is persisted;
 * the detector never touches the database.
 */
class CoverLetterRequirementDetectorTest extends TestCase
{
    private FakeCoverLetterRouter $router;

    protected function setUp(): void
    {
        parent::setUp();

        // The shipped config is the contract under test, so it is not
        // re-specified here; only the two values that decide *whether* a call
        // happens are pinned, so a later tuning of either cannot quietly change
        // what these tests mean.
        config([
            'cover_letter.detection.max_attempts' => 2,
            'cover_letter.detection.min_model_confidence' => 0.6,
            'cover_letter.detection.tier_override' => 'cheap',
            'job_sources.min_description_length' => 400,
        ]);

        // Every branch logs its decision deliberately.
        Log::spy();

        $this->router = new FakeCoverLetterRouter;
    }

    private function detector(): CoverLetterRequirementDetector
    {
        return new CoverLetterRequirementDetector($this->router);
    }

    /**
     * Neutral posting text, long enough that the listing does not read as an
     * enrichment stub. Contains no cue phrase, so a sentence appended to it is
     * the only thing the detector can react to.
     */
    private function filler(): string
    {
        return str_repeat('We build data pipelines at scale. ', 15);
    }

    private function listing(string $description): JobListing
    {
        return new JobListing([
            'title' => 'Senior Backend Engineer',
            'company' => 'Acme Corp',
            'location' => 'Remote',
            'description' => $description,
            'application_url' => 'https://jobs.example.com/1',
            'salary_min' => 120000,
            'salary_max' => 160000,
            'currency' => 'USD',
        ]);
    }

    /** A posting whose only cover-letter sentence is `$sentence`. */
    private function posting(string $sentence): JobListing
    {
        return $this->listing($this->filler().$sentence);
    }

    private function detectionJson(array $overrides = []): string
    {
        return (string) json_encode(array_merge([
            'requirement' => 'required',
            'evidence' => 'the posting asks for a letter explaining your interest',
            'confidence' => 0.9,
        ], $overrides));
    }

    /* ------------------------------------------------------------------
     | Explicit requests: settled by keywords, for free
     | ----------------------------------------------------------------- */

    /**
     * @dataProvider requiringPhrases
     */
    public function test_an_explicit_request_is_detected_without_a_model_call(string $sentence): void
    {
        $detection = $this->detector()->detect($this->posting($sentence));

        $this->assertSame(CoverLetterRequirement::Required, $detection->requirement, $sentence);
        $this->assertSame(CoverLetterDetectionMethod::Keyword, $detection->method);
        $this->assertTrue($detection->wantsCoverLetter());
        $this->assertTrue($detection->isMandatory());

        // The whole point of the keyword pass (Requirement 7.2's cost concern).
        $this->assertSame([], $this->router->calls);
        $this->assertFalse($detection->spentModelCall());
        $this->assertSame(0, $detection->attempts);

        // The decision names the phrase that produced it.
        $this->assertNotSame([], $detection->evidence);
    }

    public static function requiringPhrases(): array
    {
        return [
            'stated requirement' => ['A cover letter is required for this position.'],
            'imperative' => ['Please submit a cover letter with your application.'],
            'attach' => ['Attach your resume and a cover letter to apply.'],
            'must' => ['Applicants must include a cover letter.'],
            'hyphenated' => ['A cover-letter is required.'],
            'covering letter' => ['Please send a covering letter outlining your experience.'],
            'motivation letter' => ['A letter of motivation is required.'],
        ];
    }

    /* ------------------------------------------------------------------
     | Explicit refusals: also free, and the trap this task is about
     | ----------------------------------------------------------------- */

    /**
     * The naive version of this feature — "does the posting contain the words
     * 'cover letter'?" — gets every one of these wrong, and wrong in the
     * expensive direction Requirement 7.2 forbids.
     *
     * @dataProvider refusingPhrases
     */
    public function test_an_explicit_refusal_is_detected_without_a_model_call(string $sentence): void
    {
        $detection = $this->detector()->detect($this->posting($sentence));

        $this->assertSame(CoverLetterRequirement::NotRequested, $detection->requirement, $sentence);
        $this->assertSame(CoverLetterDetectionMethod::Keyword, $detection->method);
        $this->assertFalse($detection->wantsCoverLetter());
        $this->assertSame([], $this->router->calls);
    }

    public static function refusingPhrases(): array
    {
        return [
            'no cover letter' => ['No cover letter is needed to apply.'],
            'polite refusal' => ['Please do not include a cover letter.'],
            'not accepted' => ['Cover letters are not accepted for this role.'],
            'not required' => ['A cover letter is not required.'],
            'no need' => ['There is no need to write a cover letter.'],
        ];
    }

    /**
     * Word order must not decide the answer. "not required" trails the noun,
     * "do not require" leads it, and a colon-delimited form has neither a verb
     * nor a sentence — all three mean the same thing, and the window is read in
     * both directions precisely so they land together.
     *
     * @dataProvider negationWordOrders
     */
    public function test_negation_is_not_defeated_by_word_order(string $sentence): void
    {
        $detection = $this->detector()->detect($this->posting($sentence));

        $this->assertSame(CoverLetterRequirement::NotRequested, $detection->requirement, $sentence);
        $this->assertSame(CoverLetterDetectionMethod::Keyword, $detection->method);
        $this->assertSame([], $this->router->calls);
    }

    public static function negationWordOrders(): array
    {
        return [
            'qualifier after' => ['A cover letter is not required for this role.'],
            'qualifier before' => ['We do not require a cover letter from applicants.'],
            'label form' => ['Cover letter: not required.'],
            'distant negation' => ['We keep our process short and do not ask candidates for a cover letter.'],
            'plural after' => ['Cover letters are not necessary.'],
        ];
    }

    /* ------------------------------------------------------------------
     | Optional-but-requested is its own answer (Requirement 7.1)
     | ----------------------------------------------------------------- */

    /**
     * @dataProvider optionalPhrases
     */
    public function test_an_invited_letter_is_optional_rather_than_required(string $sentence): void
    {
        $detection = $this->detector()->detect($this->posting($sentence));

        $this->assertSame(CoverLetterRequirement::Optional, $detection->requirement, $sentence);
        $this->assertSame(CoverLetterDetectionMethod::Keyword, $detection->method);
        $this->assertSame([], $this->router->calls);

        // Requirement 7.1 generates for both states; the distinction is for the
        // caller's failure handling, not for whether to write the letter.
        $this->assertTrue($detection->wantsCoverLetter());
        $this->assertFalse($detection->isMandatory());
    }

    public static function optionalPhrases(): array
    {
        return [
            'optional' => ['Cover letter optional.'],
            'optional letter' => ['An optional cover letter may be attached.'],
            'welcome' => ['Cover letters are welcome but not needed.'],
            'if you wish' => ['You can add a cover letter if you wish.'],
        ];
    }

    /**
     * The phrase that makes cue *order* load-bearing: "not required" sits inside
     * "not required, but strongly encouraged", so a detector that checks negation
     * before optionality reads this sentence as a refusal.
     */
    public function test_a_negation_qualified_into_an_invitation_is_optional(): void
    {
        $detection = $this->detector()->detect(
            $this->posting('A cover letter is not required, but it is strongly encouraged.')
        );

        $this->assertSame(CoverLetterRequirement::Optional, $detection->requirement);
        $this->assertSame([], $this->router->calls);
    }

    /**
     * And the mirror trap: a sentence that contains "without a cover letter" and
     * means the letter is mandatory.
     */
    public function test_a_negation_that_actually_demands_a_letter_is_required(): void
    {
        $detection = $this->detector()->detect(
            $this->posting('Applications without a cover letter will not be considered.')
        );

        $this->assertSame(CoverLetterRequirement::Required, $detection->requirement);
        $this->assertSame(CoverLetterDetectionMethod::Keyword, $detection->method);
        $this->assertSame([], $this->router->calls);
    }

    /**
     * A posting that asks and refuses in different sentences is a posting we do
     * not understand, and Requirement 7.2 makes the two possible mistakes
     * unequal: skipping costs an opportunity, generating costs a tailoring call
     * plus a document in the user's application that nobody asked for.
     */
    public function test_a_contradictory_posting_resolves_to_the_cheaper_mistake(): void
    {
        $detection = $this->detector()->detect($this->posting(
            'Please submit a cover letter with your application. '
            .'Note that cover letters are not accepted for internal transfers.'
        ));

        $this->assertSame(CoverLetterRequirement::NotRequested, $detection->requirement);
        $this->assertSame([], $this->router->calls);
    }

    /* ------------------------------------------------------------------
     | Nothing to go on: the default, and never a model call
     | ----------------------------------------------------------------- */

    public function test_an_empty_description_is_not_requested_and_costs_nothing(): void
    {
        foreach (['', '   ', "\n\t "] as $blank) {
            $detection = $this->detector()->detect($this->listing($blank));

            $this->assertSame(CoverLetterRequirement::NotRequested, $detection->requirement);
            $this->assertSame(CoverLetterDetectionMethod::Absent, $detection->method);
            $this->assertFalse($detection->spentModelCall());
        }

        $this->assertSame([], $this->router->calls);
    }

    public function test_a_posting_that_never_mentions_a_letter_is_not_requested(): void
    {
        $detection = $this->detector()->detect($this->posting(
            'You will own our billing service and mentor two engineers.'
        ));

        $this->assertSame(CoverLetterRequirement::NotRequested, $detection->requirement);
        $this->assertSame(CoverLetterDetectionMethod::Absent, $detection->method);
        $this->assertSame([], $this->router->calls);
    }

    /**
     * An unenriched stub can still be *read* — a two-line posting saying "cover
     * letter required" is honoured below — but it is never worth *classifying*:
     * the text the decision would rest on has not arrived yet (Requirement 3.4),
     * and Requirement 7.2's default is the right answer for a posting we have not
     * finished fetching.
     */
    public function test_an_unenriched_description_is_never_escalated_to_a_model(): void
    {
        $stub = $this->listing('Our hiring panel reads every cover letter twice.');

        $detection = $this->detector()->detect($stub);

        $this->assertTrue($stub->needsEnrichment());
        $this->assertSame(CoverLetterRequirement::NotRequested, $detection->requirement);
        $this->assertSame(CoverLetterDetectionMethod::Absent, $detection->method);
        $this->assertSame([], $this->router->calls);
    }

    public function test_an_unenriched_stub_with_an_explicit_request_is_still_honoured(): void
    {
        $detection = $this->detector()->detect(
            $this->listing('Backend engineer. A cover letter is required.')
        );

        $this->assertSame(CoverLetterRequirement::Required, $detection->requirement);
        $this->assertSame(CoverLetterDetectionMethod::Keyword, $detection->method);
        $this->assertSame([], $this->router->calls);
    }

    /* ------------------------------------------------------------------
     | The uncertain middle: one cheap call
     | ----------------------------------------------------------------- */

    /**
     * A mention no cue class claims — the posting talks about cover letters
     * without asking for one or refusing one. This is the only shape of posting
     * that is allowed to cost anything.
     */
    public function test_an_ambiguous_mention_escalates_to_the_model_and_its_answer_is_honoured(): void
    {
        $this->router->willReturn($this->detectionJson([
            'requirement' => 'optional',
            'confidence' => 0.8,
            'evidence' => 'the panel reads letters but does not ask for one',
        ]));

        $listing = $this->posting('Our hiring panel reads every cover letter twice.');
        $detection = $this->detector()->detect($listing);

        $this->assertSame(CoverLetterRequirement::Optional, $detection->requirement);
        $this->assertSame(CoverLetterDetectionMethod::Model, $detection->method);
        $this->assertSame(0.8, $detection->confidence);
        $this->assertSame(1, $detection->attempts);
        $this->assertSame('vendor/cheap-1', $detection->modelUsed);
        $this->assertCount(1, $this->router->calls);

        $call = $this->router->calls[0];

        // Its own task type, so the spend is attributable (Requirement 4.6).
        $this->assertSame('cover_letter_detection', $call['taskType']);
        $this->assertSame(
            CoverLetterRequirementDetector::TASK_COVER_LETTER_DETECTION,
            $call['taskType']
        );
        $this->assertSame($listing, $call['relatedTo']);

        // Pinned to the cheap tier: a three-way label on a sentence is not work
        // whose stakes rise with the salary, even though this listing pays enough
        // to escalate on its own (Requirement 4.3).
        $this->assertSame('cheap', $call['tierOverride']);
        $this->assertTrue($call['context']->hasSalary());

        // Held to the same three labels the result object uses.
        $this->assertSame(
            ['required', 'optional', 'not_requested'],
            $call['jsonSchema']['properties']['requirement']['enum']
        );

        // The excerpt, not the posting: the prompt carries the mention window and
        // leaves the rest of the JD's tokens unspent.
        $user = $call['messages'][1]['content'];
        $this->assertStringContainsString('cover letter twice', $user);
        $this->assertStringNotContainsString('data pipelines at scale. We build data', $user);
    }

    public function test_a_model_answer_of_not_requested_is_honoured_too(): void
    {
        $this->router->willReturn($this->detectionJson([
            'requirement' => 'not_requested',
            'confidence' => 0.85,
        ]));

        $detection = $this->detector()->detect(
            $this->posting('Our hiring panel reads every cover letter twice.')
        );

        $this->assertSame(CoverLetterRequirement::NotRequested, $detection->requirement);
        $this->assertSame(CoverLetterDetectionMethod::Model, $detection->method);
        $this->assertFalse($detection->wantsCoverLetter());
    }

    /**
     * Requirement 7.2's asymmetry applied to the model's own hedging: an unsure
     * "required" is downgraded rather than acted on, and the downgrade is
     * recorded rather than silent.
     */
    public function test_a_low_confidence_request_is_downgraded_to_not_requested(): void
    {
        $this->router->willReturn($this->detectionJson([
            'requirement' => 'required',
            'confidence' => 0.3,
        ]));

        $detection = $this->detector()->detect(
            $this->posting('Our hiring panel reads every cover letter twice.')
        );

        $this->assertSame(CoverLetterRequirement::NotRequested, $detection->requirement);
        $this->assertSame(CoverLetterDetectionMethod::Model, $detection->method);
        $this->assertSame(0.3, $detection->confidence);

        $this->assertStringContainsString(
            'downgraded',
            implode(' ', $detection->evidence)
        );
    }

    /* ------------------------------------------------------------------
     | Model failure falls back to the default, never to an exception
     | ----------------------------------------------------------------- */

    public function test_an_unusable_response_earns_one_stricter_retry(): void
    {
        // A label outside the enum: decodable JSON that does not answer the
        // question, which no schema can prevent when a provider drops
        // `response_format`.
        $this->router->willReturn($this->detectionJson(['requirement' => 'maybe']));
        $this->router->willReturn($this->detectionJson(['requirement' => 'required', 'confidence' => 0.9]));

        $detection = $this->detector()->detect(
            $this->posting('Our hiring panel reads every cover letter twice.')
        );

        $this->assertSame(CoverLetterRequirement::Required, $detection->requirement);
        $this->assertSame(2, $detection->attempts);
        $this->assertCount(2, $this->router->calls);

        $first = $this->router->calls[0]['messages'][0]['content'];
        $retry = $this->router->calls[1]['messages'][0]['content'];
        $this->assertStringNotContainsString('ONLY valid JSON', $first);
        $this->assertStringContainsString('Return ONLY valid JSON matching this schema', $retry);
        // The schema is restated inline for a model that never saw it.
        $this->assertStringContainsString('not_requested', $retry);
    }

    /**
     * A detection that cannot be completed means "no cover letter", not "no
     * application". By construction the posting did not plainly ask for one — the
     * keyword pass would have answered if it had — so the default is both the
     * safe answer and the likely-correct one, and failing the pipeline over a
     * cheap classifier would turn a cost optimisation into an outage.
     */
    public function test_a_detection_that_keeps_failing_falls_back_to_no_cover_letter(): void
    {
        $this->router->willThrow(ModelRouterException::unparseableJson(
            'cover_letter_detection',
            'cheap',
            'vendor/cheap-1',
            'Sure! It depends on the role.'
        ));
        $this->router->willReturn($this->detectionJson(['confidence' => 'very sure']));

        $detection = $this->detector()->detect(
            $this->posting('Our hiring panel reads every cover letter twice.')
        );

        $this->assertSame(CoverLetterRequirement::NotRequested, $detection->requirement);
        $this->assertSame(CoverLetterDetectionMethod::ModelFailed, $detection->method);
        $this->assertFalse($detection->wantsCoverLetter());
        $this->assertSame(0.0, $detection->confidence);
        $this->assertSame(2, $detection->attempts);
        $this->assertCount(2, $this->router->calls);

        // Visible rather than silent: the caller logs this and an operator can
        // tell it apart from a posting that simply did not ask.
        $this->assertTrue($detection->spentModelCall());
        $this->assertStringContainsString('detection failed', implode(' ', $detection->evidence));
    }

    public function test_the_retry_allowance_is_configurable_and_never_exceeded(): void
    {
        config(['cover_letter.detection.max_attempts' => 1]);

        $this->router->willReturn($this->detectionJson(['requirement' => 'maybe']));
        $this->router->willReturn($this->detectionJson());

        $detection = $this->detector()->detect(
            $this->posting('Our hiring panel reads every cover letter twice.')
        );

        $this->assertSame(CoverLetterDetectionMethod::ModelFailed, $detection->method);
        // The second queued response was never asked for.
        $this->assertCount(1, $this->router->calls);
    }

    /* ------------------------------------------------------------------
     | Configuration drives the lists, not the code
     | ----------------------------------------------------------------- */

    public function test_the_phrase_lists_are_configuration(): void
    {
        // A house style no shipped list anticipates. Adding it must not need a
        // code change (config/cover_letter.php's stated contract).
        config(['cover_letter.detection.negation' => ['keep your prose to yourself']]);
        config(['cover_letter.detection.optionality' => []]);
        config(['cover_letter.detection.negation_overrides' => []]);

        $detection = $this->detector()->detect($this->posting(
            'A cover letter is required, but keep your prose to yourself.'
        ));

        $this->assertSame(CoverLetterRequirement::NotRequested, $detection->requirement);
        $this->assertSame(['keep your prose to yourself'], $detection->evidence);
        $this->assertSame([], $this->router->calls);
    }

    public function test_a_configured_phrase_is_matched_however_it_is_punctuated(): void
    {
        // Written as an operator would copy it out of a posting; matched against
        // text that punctuates it differently.
        config(['cover_letter.detection.negation' => ["Don't send us a cover letter!"]]);

        $detection = $this->detector()->detect($this->posting(
            "Don\u{2019}t send us a cover letter — we read resumes only."
        ));

        $this->assertSame(CoverLetterRequirement::NotRequested, $detection->requirement);
        $this->assertSame([], $this->router->calls);
    }
}

/**
 * Records what the detector asked for and returns queued responses.
 *
 * A subclass rather than a mock, as in {@see ResumeTailoringServiceTest}, so
 * {@see ModelRouterService::complete()}'s signature is enforced by PHP — most
 * importantly the trailing `$tierOverride`, which this detector is the second
 * caller in the system to use.
 */
class FakeCoverLetterRouter extends ModelRouterService
{
    /** @var array<int, array<string, mixed>> */
    public array $calls = [];

    /** @var array<int, ModelCompletionResult|Throwable> */
    private array $queue = [];

    public function willReturn(string $content): void
    {
        $decoded = json_decode($content, true);

        $this->queue[] = new ModelCompletionResult(
            content: $content,
            parsedJson: is_array($decoded) ? $decoded : [],
            modelUsed: 'vendor/cheap-1',
            tierUsed: 'cheap',
            inputTokens: 240,
            outputTokens: 40,
            estimatedCostUsd: 0.00004,
        );
    }

    public function willThrow(Throwable $e): void
    {
        $this->queue[] = $e;
    }

    public function complete(
        string $taskType,
        array $messages,
        ModelTierContext $context,
        ?array $jsonSchema = null,
        ?Model $relatedTo = null,
        ?string $tierOverride = null,
    ): ModelCompletionResult {
        $this->calls[] = [
            'taskType' => $taskType,
            'messages' => $messages,
            'context' => $context,
            'jsonSchema' => $jsonSchema,
            'relatedTo' => $relatedTo,
            'tierOverride' => $tierOverride,
        ];

        if ($this->queue === []) {
            throw new \LogicException('FakeCoverLetterRouter received an unexpected call for task '.$taskType);
        }

        $next = array_shift($this->queue);

        if ($next instanceof Throwable) {
            throw $next;
        }

        return $next;
    }
}

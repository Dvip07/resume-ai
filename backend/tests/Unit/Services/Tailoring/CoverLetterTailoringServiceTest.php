<?php

namespace Tests\Unit\Services\Tailoring;

use App\Exceptions\CoverLetterTailoringException;
use App\Exceptions\ModelRouterException;
use App\Models\JobListing;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\ModelCompletionResult;
use App\Services\ModelRouterService;
use App\Services\ModelTierContext;
use App\Services\Tailoring\CoverLetterRecipient;
use App\Services\Tailoring\CoverLetterTailoringService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Tests\TestCase;
use Throwable;

/**
 * The generation step of Requirement 7.1 (task 12.3): a routed,
 * schema-constrained model call whose prose is assembled *into* a factual
 * skeleton taken from the listing, the profile and the clock.
 *
 * Structured as {@see ResumeTailoringServiceTest} is, and for the same reasons.
 * The router is a recording subclass rather than an HTTP fake, because what is
 * under test is the service's own behaviour — which task type and schema it asks
 * for, what it refuses to accept, and what it does with an unusable response —
 * none of which should depend on OpenRouter's wire format. Nothing is persisted,
 * and the one test that reaches the template *renders* it and stops, since
 * compiling would need tectonic installed.
 *
 * The date is fixed with `Carbon::setTestNow()` throughout: the service reads
 * `now()`, which is exactly the seam that makes it deterministic without a
 * clock parameter.
 */
class CoverLetterTailoringServiceTest extends TestCase
{
    private FakeCoverLetterWriterRouter $router;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cover_letter.generation.max_attempts' => 2,
            'cover_letter.generation.max_description_chars' => 12000,
            'cover_letter.generation.max_profile_chars' => 12000,
            'cover_letter.generation.max_experience_entries' => 4,
            'cover_letter.generation.min_paragraphs' => 2,
            'cover_letter.generation.max_paragraphs' => 4,
            'cover_letter.generation.max_paragraph_chars' => 900,
            'cover_letter.generation.max_closing_chars' => 40,
            'cover_letter.generation.date_format' => 'F j, Y',
            'cover_letter.generation.default_template_key' => 'default',
        ]);

        // The service reads `now()` rather than taking a date, so this is the
        // whole of its clock seam.
        Carbon::setTestNow(Carbon::parse('2025-03-09 08:30:00'));

        // The failure paths log deliberately.
        Log::spy();

        $this->router = new FakeCoverLetterWriterRouter;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function service(): CoverLetterTailoringService
    {
        return new CoverLetterTailoringService($this->router);
    }

    private function listing(array $overrides = []): JobListing
    {
        return new JobListing(array_merge([
            'title' => 'Senior Backend Engineer',
            'company' => 'Analytical & Co',
            'location' => 'Remote',
            'description' => str_repeat('We need Laravel, PostgreSQL and Kubernetes experience. ', 20),
            'application_url' => 'https://jobs.example.com/1',
            'salary_min' => 120000,
            'salary_max' => 160000,
            'currency' => 'USD',
        ], $overrides));
    }

    /** A profile in the exact shapes the resume parser and `Api\ProfileController` write. */
    private function profile(array $overrides = []): UserProfile
    {
        $profile = new UserProfile(array_merge([
            'user_id' => 7,
            'skills' => [
                'primary' => ['Laravel', 'PostgreSQL', 'PHP'],
                'secondary' => ['Redis', 'Docker'],
            ],
            'location' => ['city' => 'Austin', 'state' => 'TX', 'country' => 'US'],
            'linkedin_url' => 'https://linkedin.com/in/ada',
            'github_url' => '',
            'portfolio_url' => '',
            'experience' => [
                [
                    'company' => 'Globex',
                    'position' => 'Staff Engineer',
                    'duration' => 30,
                    'achievements' => ['Owned the billing service', 'Mentored four engineers'],
                ],
            ],
            'education' => [
                [
                    'institution' => 'University of London',
                    'degree' => 'BSc',
                    'fieldOfStudy' => 'Mathematics',
                    'startYear' => 2009,
                    'endYear' => 2012,
                ],
            ],
            'parsed_keywords' => 'php, laravel, sql',
            'resume_text' => 'Backend engineer with six years of Laravel experience.',
        ], $overrides));

        // Name and email come from the user, and the relation is set rather than
        // saved so the whole suite runs without a database.
        $profile->setRelation('user', new User(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']));

        return $profile;
    }

    /** A well-formed model response: body prose and nothing else. */
    private function letterJson(array $overrides = []): string
    {
        return (string) json_encode(array_merge([
            'paragraphs' => [
                'I am writing because the backend team you are building is solving the billing '
                    .'problems I have spent the last three years on.',
                'At Globex I owned the billing service end to end and mentored four engineers to '
                    .'independent delivery, work that mapped directly onto Laravel and PostgreSQL.',
                'I would welcome the chance to talk about how that experience fits what you need.',
            ],
        ], $overrides));
    }

    /* ------------------------------------------------------------------
     | Happy path
     | ----------------------------------------------------------------- */

    public function test_it_returns_the_template_contract_with_the_models_prose(): void
    {
        $this->router->willReturn($this->letterJson());

        $letter = $this->service()->tailor($this->listing(), $this->profile());
        $content = $letter->forTemplate();

        // The model's whole contribution.
        $this->assertCount(3, $content['paragraphs']);
        $this->assertStringContainsString('billing problems', $content['paragraphs'][0]);

        // Everything else, from somewhere that cannot be wrong.
        $this->assertSame('Ada Lovelace', $content['name']);
        $this->assertSame('Analytical & Co', $content['company']);
        $this->assertSame('Senior Backend Engineer', $content['job_title']);
        $this->assertSame('March 9, 2025', $content['date']);
        $this->assertSame(
            ['ada@example.com', 'Austin, TX, US', 'https://linkedin.com/in/ada'],
            $content['contact']
        );

        $this->assertSame(1, $letter->attempts);
        $this->assertSame('vendor/standard-1', $letter->modelUsed);
        $this->assertSame('standard', $letter->tierUsed);
        $this->assertSame($this->letterJson(), $letter->rawOutput);

        // The tokens the fabrication guard of task 11.5 diffs the rendered letter
        // against; none of them passed through a model.
        $this->assertSame([
            'name' => 'Ada Lovelace',
            'company' => 'Analytical & Co',
            'job_title' => 'Senior Backend Engineer',
            'date' => 'March 9, 2025',
            'recipient_name' => null,
        ], $letter->facts);
    }

    public function test_it_asks_for_the_cover_letter_tailor_task_type_with_a_prose_only_schema(): void
    {
        $this->router->willReturn($this->letterJson());
        $listing = $this->listing();

        $this->service()->tailor($listing, $this->profile());

        $call = $this->router->calls[0];
        $this->assertSame(CoverLetterTailoringService::TASK_COVER_LETTER_TAILOR, $call['taskType']);
        $this->assertSame('cover_letter_tailor', $call['taskType']);
        // Token spend is attributed to the listing being applied to (Req 4.6).
        $this->assertSame($listing, $call['relatedTo']);

        // The structural guarantee: the only fields in the schema are prose, so
        // there is no property for a recipient, a company, a date or a job title
        // and therefore nothing for the model to fabricate.
        $this->assertSame(['paragraphs'], $call['jsonSchema']['required']);
        $this->assertSame(
            ['paragraphs', 'closing'],
            array_keys($call['jsonSchema']['properties'])
        );

        // Unlike detection, generation is not pinned to a tier: writing a page of
        // persuasive prose is work Requirement 4.3 wants escalated when the role
        // justifies it.
        $this->assertNull($call['tierOverride']);
        $this->assertTrue($call['context']->hasSalary());
        $this->assertSame(160000, $call['context']->salaryBasis());
    }

    public function test_the_prompt_carries_the_posting_and_the_candidates_real_achievements(): void
    {
        $this->router->willReturn($this->letterJson());

        $this->service()->tailor($this->listing(), $this->profile());

        $system = $this->router->calls[0]['messages'][0]['content'];
        $user = $this->router->calls[0]['messages'][1]['content'];

        $this->assertStringContainsString('Kubernetes experience', $user);
        $this->assertStringContainsString('Owned the billing service', $user);
        $this->assertStringContainsString('University of London', $user);

        // The model is told not to address anyone and not to invent a contact,
        // which is the prompt-level half of the guarantee the schema enforces.
        $this->assertStringContainsString('never address anyone by name', $system);
        $this->assertStringContainsString('never invent a hiring manager', $system);

        // The letterhead is not the model's business, so its contents are not
        // sent.
        $this->assertStringNotContainsString('ada@example.com', $user);
    }

    /* ------------------------------------------------------------------
     | The model cannot name a recipient or a company
     | ----------------------------------------------------------------- */

    public function test_the_model_cannot_introduce_a_recipient_or_a_company(): void
    {
        // A model ignoring the contract and volunteering every field it was not
        // given: a hiring manager who does not exist, a different employer, a
        // different job.
        $this->router->willReturn($this->letterJson([
            'recipient_name' => 'Sarah Mitchell',
            'recipient_title' => 'Director of Talent',
            'company' => 'Fabricated Inc',
            'company_address' => ['1 Invented Way', 'Nowhere'],
            'job_title' => 'Chief Architect',
            'name' => 'Someone Else',
            'date' => 'January 1, 1999',
        ]));

        $content = $this->service()->tailor($this->listing(), $this->profile())->forTemplate();

        // Null rather than a guess, which is what makes the template's fallback
        // fire.
        $this->assertNull($content['recipient_name']);
        $this->assertNull($content['recipient_title']);
        $this->assertSame([], $content['company_address']);

        $this->assertSame('Analytical & Co', $content['company']);
        $this->assertSame('Senior Backend Engineer', $content['job_title']);
        $this->assertSame('Ada Lovelace', $content['name']);
        $this->assertSame('March 9, 2025', $content['date']);

        $encoded = (string) json_encode($content);
        foreach (['Sarah Mitchell', 'Director of Talent', 'Fabricated Inc', 'Invented Way',
            'Chief Architect', 'Someone Else', '1999'] as $fabrication) {
            $this->assertStringNotContainsString($fabrication, $encoded);
        }
    }

    public function test_a_recipient_the_caller_supplies_is_passed_through_and_reaches_the_salutation(): void
    {
        $this->router->willReturn($this->letterJson());

        $letter = $this->service()->tailor(
            $this->listing(),
            $this->profile(),
            // The only route in: a caller with a real source for the name.
            new CoverLetterRecipient(
                name: '  Priya Raman  ',
                title: 'Engineering Manager',
                addressLines: ['400 Market St', '  ', 'Austin, TX'],
            ),
        );

        $content = $letter->forTemplate();

        $this->assertSame('Priya Raman', $content['recipient_name']);
        $this->assertSame('Engineering Manager', $content['recipient_title']);
        $this->assertSame(['400 Market St', 'Austin, TX'], $content['company_address']);
        $this->assertSame('Priya Raman', $letter->facts['recipient_name']);

        $tex = View::make('latex::cover-letter', $content)->render();
        $this->assertStringContainsString('Dear Priya Raman,', $tex);
        $this->assertStringNotContainsString('Dear Hiring Manager,', $tex);
    }

    public function test_without_a_recipient_the_template_fallback_addresses_the_hiring_manager(): void
    {
        $this->router->willReturn($this->letterJson());

        $content = $this->service()->tailor($this->listing(), $this->profile())->forTemplate();
        $tex = View::make('latex::cover-letter', $content)->render();

        // The correct output, not a degraded one: it is what a careful human
        // writes when they do not know who will read the letter.
        $this->assertStringContainsString('Dear Hiring Manager,', $tex);
    }

    /* ------------------------------------------------------------------
     | The date
     | ----------------------------------------------------------------- */

    public function test_the_date_is_the_current_date_in_the_configured_format(): void
    {
        config(['cover_letter.generation.date_format' => 'j F Y']);
        Carbon::setTestNow(Carbon::parse('2026-12-01 23:59:00'));

        $this->router->willReturn($this->letterJson());

        $this->assertSame(
            '1 December 2026',
            $this->service()->tailor($this->listing(), $this->profile())->forTemplate()['date']
        );
    }

    public function test_the_date_is_resolved_once_so_a_re_render_cannot_move_it(): void
    {
        $this->router->willReturn($this->letterJson());

        $letter = $this->service()->tailor($this->listing(), $this->profile());

        // A compile-retry loop re-renders the stored content array, possibly after
        // midnight. The date travels with the result rather than being read again.
        Carbon::setTestNow(Carbon::parse('2025-03-10 00:05:00'));

        $this->assertSame('March 9, 2025', $letter->forTemplate()['date']);
        $this->assertStringContainsString(
            'March 9, 2025',
            View::make('latex::cover-letter', $letter->forTemplate())->render()
        );
    }

    /* ------------------------------------------------------------------
     | The model cannot inject markup
     | ----------------------------------------------------------------- */

    public function test_latex_in_a_paragraph_is_rejected_and_retried_as_plain_text(): void
    {
        $this->router->willReturn($this->letterJson([
            'paragraphs' => [
                'I am \\textbf{genuinely} excited about this role.',
                'My work \\input{/etc/passwd} speaks for itself.',
            ],
        ]));
        $this->router->willReturn($this->letterJson());

        $letter = $this->service()->tailor($this->listing(), $this->profile());

        $this->assertSame(2, $letter->attempts);
        $this->assertStringNotContainsString('\\textbf', $letter->bodyText());
        $this->assertStringNotContainsString('\\input', $letter->bodyText());

        // The retry says what was wrong, in terms the model can act on.
        $retry = $this->router->calls[1]['messages'][0]['content'];
        $this->assertStringContainsString('contains LaTeX markup', $retry);
        $this->assertStringContainsString('Return ONLY valid JSON matching this schema', $retry);
    }

    public function test_markdown_emphasis_in_a_paragraph_is_rejected_too(): void
    {
        $this->router->willReturn($this->letterJson([
            'paragraphs' => [
                'I am **genuinely excited** about this role.',
                'My experience is a close match for what you describe.',
            ],
        ]));
        $this->router->willReturn($this->letterJson());

        $letter = $this->service()->tailor($this->listing(), $this->profile());

        $this->assertSame(2, $letter->attempts);
        $this->assertStringNotContainsString('**', $letter->bodyText());
        $this->assertStringContainsString(
            'contains Markdown formatting',
            $this->router->calls[1]['messages'][0]['content']
        );
    }

    public function test_prose_punctuation_the_template_escapes_is_not_mistaken_for_markup(): void
    {
        // Every one of these is legitimate letter prose and every one is a LaTeX
        // special character. The template escapes them; rejecting them here would
        // throw away good writing.
        $this->router->willReturn($this->letterJson([
            'paragraphs' => [
                'I grew R&D throughput 40% on a $2M budget and held ~99.9% uptime.',
                'I track cost_per_unit weekly and would bring the same discipline here.',
            ],
        ]));

        $letter = $this->service()->tailor($this->listing(), $this->profile());

        $this->assertSame(1, $letter->attempts);
        $this->assertStringContainsString('R&D throughput 40%', $letter->bodyText());
    }

    /* ------------------------------------------------------------------
     | Output budgets: a cover letter is one page
     | ----------------------------------------------------------------- */

    public function test_paragraph_count_and_length_are_bounded_by_config(): void
    {
        config([
            'cover_letter.generation.max_paragraphs' => 2,
            'cover_letter.generation.max_paragraph_chars' => 60,
        ]);

        $this->router->willReturn($this->letterJson([
            'paragraphs' => [
                str_repeat('I would be a considerable asset to this team. ', 6),
                'Second paragraph, which survives.',
                'Third paragraph, which does not.',
                'Fourth paragraph, likewise.',
            ],
        ]));

        $paragraphs = $this->service()->tailor($this->listing(), $this->profile())->paragraphs();

        // Truncated rather than retried: three good paragraphs are two good
        // paragraphs plus one, and re-prompting would buy nothing.
        $this->assertCount(2, $paragraphs);
        $this->assertLessThanOrEqual(60, mb_strlen($paragraphs[0]));
        $this->assertSame('Second paragraph, which survives.', $paragraphs[1]);
    }

    public function test_the_configured_bounds_reach_the_prompt_and_the_schema(): void
    {
        config([
            'cover_letter.generation.min_paragraphs' => 3,
            'cover_letter.generation.max_paragraphs' => 3,
            'cover_letter.generation.max_paragraph_chars' => 500,
        ]);

        $this->router->willReturn($this->letterJson());

        $this->service()->tailor($this->listing(), $this->profile());

        $call = $this->router->calls[0];
        $this->assertStringContainsString('between 3 and 3 paragraphs', $call['messages'][1]['content']);
        $this->assertStringContainsString('at most 500 characters', $call['messages'][1]['content']);
        $this->assertSame(3, $call['jsonSchema']['properties']['paragraphs']['minItems']);
        $this->assertSame(3, $call['jsonSchema']['properties']['paragraphs']['maxItems']);
    }

    public function test_a_single_paragraph_response_is_treated_as_unusable(): void
    {
        // Not a short letter: an opening line with no argument and no close, which
        // means the model answered a different question.
        $this->router->willReturn($this->letterJson([
            'paragraphs' => ['I would love to work at your company.'],
        ]));
        $this->router->willReturn($this->letterJson());

        $letter = $this->service()->tailor($this->listing(), $this->profile());

        $this->assertSame(2, $letter->attempts);
        $this->assertCount(3, $letter->paragraphs());
    }

    /* ------------------------------------------------------------------
     | The closing: the template's job unless the model does it
     | ----------------------------------------------------------------- */

    public function test_a_closing_the_model_supplies_is_used_and_an_absent_one_is_left_to_the_template(): void
    {
        $this->router->willReturn($this->letterJson(['closing' => 'With thanks,']));
        $this->router->willReturn($this->letterJson());

        $withClosing = $this->service()->tailor($this->listing(), $this->profile())->forTemplate();
        $this->assertSame('With thanks,', $withClosing['closing']);
        $this->assertStringContainsString(
            'With thanks,',
            View::make('latex::cover-letter', $withClosing)->render()
        );

        $withoutClosing = $this->service()->tailor($this->listing(), $this->profile())->forTemplate();
        $this->assertNull($withoutClosing['closing']);
        // The degradation rule lives in the template, so an absent closing is
        // still a signed letter.
        $this->assertStringContainsString(
            'Sincerely,',
            View::make('latex::cover-letter', $withoutClosing)->render()
        );
    }

    /* ------------------------------------------------------------------
     | One stricter retry, then a typed failure
     | ----------------------------------------------------------------- */

    public function test_a_malformed_payload_earns_one_retry_with_a_stricter_instruction(): void
    {
        $this->router->willReturn($this->letterJson(['paragraphs' => []]));
        $this->router->willReturn($this->letterJson());

        $letter = $this->service()->tailor($this->listing(), $this->profile());

        $this->assertSame(2, $letter->attempts);
        $this->assertCount(2, $this->router->calls);

        $first = $this->router->calls[0]['messages'][0]['content'];
        $retry = $this->router->calls[1]['messages'][0]['content'];
        $this->assertStringNotContainsString('ONLY valid JSON', $first);
        $this->assertStringContainsString('Return ONLY valid JSON matching this schema', $retry);
        // The schema is restated inline, for a model whose provider dropped
        // `response_format`.
        $this->assertStringContainsString('"paragraphs"', $retry);
    }

    public function test_an_unparseable_router_response_is_retried_once_then_raises_a_typed_exception(): void
    {
        $this->router->willThrow(ModelRouterException::unparseableJson(
            'cover_letter_tailor',
            'standard',
            'vendor/standard-1',
            'Sure! Here is a cover letter: Dear Hiring Manager, ...'
        ));
        $this->router->willThrow(ModelRouterException::unparseableJson(
            'cover_letter_tailor',
            'standard',
            'vendor/standard-1',
            'Of course. Dear Sir or Madam, ...'
        ));

        try {
            $this->service()->tailor($this->listing(), $this->profile());
            $this->fail('Expected a CoverLetterTailoringException once both attempts failed.');
        } catch (CoverLetterTailoringException $e) {
            $this->assertCount(2, $e->attempts);
            $this->assertSame('cover_letter_tailor', $e->context['task_type']);
            // Both failures were about content, which tells the caller this is a
            // model that will not follow the contract rather than an outage.
            $this->assertTrue($e->failedOnParsing());
            $this->assertNotSame('', $e->reviewReason());
        }

        $this->assertCount(2, $this->router->calls);
    }

    public function test_an_unparseable_response_is_retried_and_can_still_succeed(): void
    {
        $this->router->willThrow(ModelRouterException::unparseableJson(
            'cover_letter_tailor',
            'standard',
            'vendor/standard-1',
            'Sure! Here is a cover letter: ...'
        ));
        $this->router->willReturn($this->letterJson());

        $this->assertSame(2, $this->service()->tailor($this->listing(), $this->profile())->attempts);
    }

    public function test_the_retry_allowance_is_configurable_and_never_exceeded(): void
    {
        config(['cover_letter.generation.max_attempts' => 1]);

        $this->router->willReturn($this->letterJson(['paragraphs' => []]));
        $this->router->willReturn($this->letterJson());

        $this->expectException(CoverLetterTailoringException::class);

        try {
            $this->service()->tailor($this->listing(), $this->profile());
        } finally {
            // One attempt only: the second queued response was never asked for.
            $this->assertCount(1, $this->router->calls);
        }
    }

    /* ------------------------------------------------------------------
     | Nothing to write from: no model call at all
     | ----------------------------------------------------------------- */

    public function test_a_listing_with_no_description_fails_without_calling_a_model(): void
    {
        try {
            $this->service()->tailor($this->listing(['description' => '  ']), $this->profile());
            $this->fail('Expected a CoverLetterTailoringException for an unwritable listing.');
        } catch (CoverLetterTailoringException $e) {
            $this->assertStringContainsString('needs enrichment', $e->getMessage());
        }

        $this->assertSame([], $this->router->calls);
    }

    public function test_an_empty_profile_fails_without_calling_a_model(): void
    {
        $empty = $this->profile(['skills' => [], 'experience' => [], 'education' => []]);

        try {
            $this->service()->tailor($this->listing(), $empty);
            $this->fail('Expected a CoverLetterTailoringException for an empty profile.');
        } catch (CoverLetterTailoringException $e) {
            $this->assertStringContainsString('needs parsing first', $e->getMessage());
        }

        $this->assertSame([], $this->router->calls);
    }

    /* ------------------------------------------------------------------
     | Template selection
     | ----------------------------------------------------------------- */

    public function test_the_default_template_key_is_read_from_configuration_per_call(): void
    {
        $service = $this->service();

        config(['cover_letter.generation.default_template_key' => "  compact\n"]);
        $this->assertSame('compact', $service->defaultTemplateKey());

        // Every shape an env var or a hand-edited config can be empty in. Each
        // yields the shipped default rather than an empty key, which
        // `renderCoverLetter()` would reject outright.
        foreach ([null, '', '   ', 0, [], ['default']] as $useless) {
            config(['cover_letter.generation.default_template_key' => $useless]);

            $this->assertSame(
                'default',
                $service->defaultTemplateKey(),
                sprintf('config value %s should have fallen back to the shipped default', json_encode($useless)),
            );
        }
    }

    /* ------------------------------------------------------------------
     | The template accepts the output
     | ----------------------------------------------------------------- */

    public function test_the_output_renders_a_complete_latex_document(): void
    {
        $this->router->willReturn($this->letterJson());

        $content = $this->service()->tailor($this->listing(), $this->profile())->forTemplate();

        // Rendered only, never compiled: tectonic is not installed in CI, and what
        // is under test is that the content array satisfies the template's
        // contract — every key it reads is present and the right shape.
        $tex = View::make('latex::cover-letter', $content)->render();

        $this->assertStringContainsString('\documentclass', $tex);
        $this->assertStringContainsString('\begin{document}', $tex);
        $this->assertStringContainsString('\end{document}', $tex);

        $this->assertStringContainsString('Ada Lovelace', $tex);
        $this->assertStringContainsString('ada@example.com', $tex);
        $this->assertStringContainsString('March 9, 2025', $tex);
        $this->assertStringContainsString('Dear Hiring Manager,', $tex);
        $this->assertStringContainsString('billing problems', $tex);
        $this->assertStringContainsString('Sincerely,', $tex);

        // Requirement 6.3's escaping guarantee end to end: the ampersand in the
        // real company name is an alignment error unescaped, and the subject line
        // carries the two facts a reader checks first whether the prose mentions
        // them or not.
        $this->assertStringContainsString('Re: Senior Backend Engineer at Analytical \& Co', $tex);
    }
}

/**
 * Records what the cover-letter service asked for and returns queued responses.
 *
 * A subclass rather than a mock so the type contract of
 * {@see ModelRouterService::complete()} is enforced by PHP: if the real
 * signature changes, this fake stops compiling instead of silently drifting.
 * Declared here rather than shared with {@see FakeTailoringRouter}, which lives
 * inside its own test file for the same reason.
 */
class FakeCoverLetterWriterRouter extends ModelRouterService
{
    /** @var array<int, array<string, mixed>> */
    public array $calls = [];

    /** @var array<int, ModelCompletionResult|Throwable> */
    private array $queue = [];

    /** Queue a successful completion whose content is `$content`. */
    public function willReturn(string $content): void
    {
        $decoded = json_decode($content, true);

        $this->queue[] = new ModelCompletionResult(
            content: $content,
            parsedJson: is_array($decoded) ? $decoded : [],
            modelUsed: 'vendor/standard-1',
            tierUsed: 'standard',
            inputTokens: 1500,
            outputTokens: 400,
            estimatedCostUsd: 0.0044,
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
            throw new \LogicException('FakeCoverLetterWriterRouter received an unexpected call for task '.$taskType);
        }

        $next = array_shift($this->queue);

        if ($next instanceof Throwable) {
            throw $next;
        }

        return $next;
    }
}

<?php

namespace App\Services\Tailoring;

use App\Enums\CoverLetterDetectionMethod;
use App\Enums\CoverLetterRequirement;
use App\Exceptions\ModelRouterException;
use App\Models\JobListing;
use App\Services\ModelCompletionResult;
use App\Services\ModelRouterService;
use App\Services\ModelTierContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Decides whether a posting asks for a cover letter (Requirements 7.1, 7.2,
 * task 12.2), so task 12.3 can dispatch tailoring for the postings that do and
 * spend nothing on the ones that do not.
 *
 * ## Why this is not just another prompt
 *
 * Its two siblings in this namespace and in `Services\Scoring` exist because
 * their questions genuinely need a language model: nothing but a model can write
 * a tailored bullet or weigh a fit. This class's question is different — most
 * postings answer it in so many words, and the answer is three-valued. So the
 * shape here is *keyword first, model second*, and the model is the exception
 * rather than the path:
 *
 *  1. **No mention, no call.** If the description never names a cover letter,
 *     Requirement 7.2's default stands unopposed and the answer is
 *     {@see CoverLetterRequirement::NotRequested} with
 *     {@see CoverLetterDetectionMethod::Absent}. This is the majority of
 *     postings and it costs nothing. An empty or unenriched description takes
 *     the same exit — see {@see detect()}.
 *  2. **Mentioned with a cue, no call.** Each mention is read together with the
 *     text around it and classified by the configured cue lists. A posting that
 *     says "cover letter required", "cover letter optional" or "please do not
 *     include a cover letter" is settled here, at zero cost, with the matched
 *     phrase kept as evidence.
 *  3. **Mentioned ambiguously, one cheap call.** Only a mention that no cue
 *     class claims escalates, and only the mention windows are sent — not the
 *     posting. This is the "uncertain middle" the task type
 *     `cover_letter_detection` exists to account for (Requirement 4.6).
 *
 * A model-first design would invert the economics of the requirement it
 * implements: Requirement 7.2 is a cost control, and paying a model to read the
 * words "cover letter optional" on every posting in the pipeline would spend
 * most of what skipping the letters saves.
 *
 * ## Negation and optionality are the actual problem
 *
 * The naive version of this feature — does the string "cover letter" appear? —
 * is wrong in the most expensive direction. Postings say "no cover letter
 * required", "cover letters are optional", "please do not include a cover
 * letter", and every one of those contains the phrase. So classification happens
 * per *mention*, over a window of surrounding text, in a fixed cue order that is
 * documented in config/cover_letter.php and summarised here:
 *
 *  - `negation_overrides` first, because "applications **without a cover
 *    letter** will not be considered" is a negation that demands a letter, and
 *    every later class reads it backwards;
 *  - `optionality` next, so "not required, but strongly encouraged" is optional
 *    rather than refused — the "not required" inside it would otherwise win;
 *  - `negation` next: explicitly unwanted;
 *  - `request` last: required or asked for.
 *
 * Because the window is read in both directions, word order does not defeat any
 * of this: "we do not require a cover letter" and "a cover letter is not
 * required" reach the same class from opposite sides.
 *
 * When mentions disagree, refusal beats optionality beats requirement. That
 * ranking is Requirement 7.2's asymmetry made concrete: a posting that says
 * "cover letter" twice and contradicts itself is exactly the case where
 * generating a document is the worse of the two mistakes.
 *
 * ## Failure means "no letter", not "no application"
 *
 * Unlike {@see ResumeTailoringService}, this class throws nothing. A detection
 * failure — the classifier is down, or returns something unusable twice — is
 * answered with {@see CoverLetterDetectionMethod::ModelFailed} and the default
 * requirement. Two reasons. It only ever happens on the ambiguous middle, so by
 * construction the posting did *not* plainly ask for a letter; and detection is a
 * gate in front of optional work, so failing the whole application because a
 * cheap classifier hiccuped would turn a cost optimisation into an outage. The
 * method on the result is what makes that visible rather than silent.
 *
 * ## Scope
 *
 * Reading the *application form* (the other half of Requirement 7.1's "job
 * description or application form") is not done here: the form is only visible to
 * the browser automation of Requirement 9, which runs long after this decision.
 * Rendering, dispatching and persisting belong to task 12.3. This class takes a
 * listing and returns a {@see CoverLetterDetection}; that is its whole contract.
 */
class CoverLetterRequirementDetector
{
    /** Task type recorded in `model_usage_logs` (Requirement 4.6). */
    public const TASK_COVER_LETTER_DETECTION = 'cover_letter_detection';

    /** Cue classes, in the order one mention's window is tested against them. */
    private const CUE_ORDER = [
        'negation_overrides' => CoverLetterRequirement::Required,
        'optionality' => CoverLetterRequirement::Optional,
        'negation' => CoverLetterRequirement::NotRequested,
        'request' => CoverLetterRequirement::Required,
    ];

    /**
     * Aggregate precedence when mentions disagree: the first requirement in this
     * list that any mention reached wins.
     */
    private const AGGREGATE_PRECEDENCE = [
        CoverLetterRequirement::NotRequested,
        CoverLetterRequirement::Optional,
        CoverLetterRequirement::Required,
    ];

    /** Marks a sentence break so a cue cannot be read across one. */
    private const SENTENCE_BREAK = '|';

    public function __construct(private readonly ModelRouterService $router) {}

    /**
     * What does `$listing` ask for?
     *
     * Never throws: see the class docblock. The worst case is Requirement 7.2's
     * default with a method that says why.
     */
    public function detect(JobListing $listing): CoverLetterDetection
    {
        $normalized = $this->normalize((string) $listing->description);

        // Requirement 7.2, the cheapest way: no text, no mention, no call. An
        // unenriched listing is treated as *evidence-free* rather than as
        // ambiguous further down, which is why it is worth separating from a
        // genuinely silent posting only in the log, not in the answer.
        if ($normalized === '') {
            return $this->absent($listing, 'the listing has no description to inspect');
        }

        $mentions = $this->mentions($normalized);

        if ($mentions === []) {
            return $this->absent($listing, 'the description never mentions a cover letter');
        }

        [$requirement, $evidence] = $this->classifyMentions($mentions);

        if ($requirement !== null) {
            $detection = new CoverLetterDetection(
                requirement: $requirement,
                method: CoverLetterDetectionMethod::Keyword,
                evidence: $evidence,
                confidence: $this->floatConfig('keyword_confidence', 0.9),
            );

            Log::info('Cover letter requirement detected from keywords.', [
                'job_listing_id' => $listing->getKey(),
            ] + $detection->context());

            return $detection;
        }

        // Every mention was a bare one. This is the only branch that costs
        // anything — and it still does not, when the description is too short to
        // be a real posting: an enrichment stub that happens to name a cover
        // letter is not worth a classification, and Requirement 7.2's default is
        // the right answer for text that has not arrived yet.
        if ($listing->needsEnrichment()) {
            return $this->absent(
                $listing,
                'the description is too short to classify and needs enrichment first'
            );
        }

        return $this->classifyWithModel($listing, $mentions);
    }

    /* ---------------------------------------------------------------------
     | The free pass: mentions and the cues around them
     | -------------------------------------------------------------------- */

    /**
     * Fold description text into the one form both the haystack and the
     * configured cue phrases are compared in.
     *
     * Lowercased; apostrophes dropped so "don't" and "dont" are one phrase;
     * sentence-ending punctuation replaced by {@see SENTENCE_BREAK} so a cue
     * cannot span two sentences ("not required. but we..." is not "not required
     * but"); every other non-alphanumeric character replaced by a space, which
     * quietly folds the shapes postings actually use — "cover-letter",
     * "cover letter(s)", "resume/cover letter" — onto the plain term.
     *
     * Applied to the config lists too, at read time, so an operator may write a
     * phrase exactly as they found it in a posting, punctuation and all, without
     * knowing any of the above.
     */
    protected function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = str_replace(["\u{2019}", "\u{2018}", "'", '`'], '', $text);
        $text = preg_replace('/[.!?;:]+/u', ' '.self::SENTENCE_BREAK.' ', $text) ?? $text;
        $text = preg_replace('/[^a-z0-9'.preg_quote(self::SENTENCE_BREAK, '/').']+/u', ' ', $text) ?? $text;

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Every window of text around a cover-letter mention, de-duplicated.
     *
     * De-duplication matters more than it looks: "cover letters" contains "cover
     * letter", so a single phrase matches two configured terms at the same
     * offset and would otherwise be classified — and quoted as evidence — twice.
     *
     * @return array<int, string>
     */
    protected function mentions(string $normalized): array
    {
        $terms = $this->phrases('mention_terms');

        if ($terms === []) {
            return [];
        }

        $before = max(0, (int) config('cover_letter.detection.window_chars_before', 120));
        $after = max(0, (int) config('cover_letter.detection.window_chars_after', 160));

        $windows = [];

        foreach ($terms as $term) {
            $offset = 0;

            while (($position = mb_strpos($normalized, $term, $offset)) !== false) {
                $start = max(0, $position - $before);
                $length = ($position - $start) + mb_strlen($term) + $after;

                $windows[] = trim(mb_substr($normalized, $start, $length));

                // Advance past this occurrence only, so "cover letter ... cover
                // letter" yields two mentions rather than one.
                $offset = $position + mb_strlen($term);
            }
        }

        return array_values(array_unique($windows));
    }

    /**
     * Read every mention and reconcile them.
     *
     * Returns `[null, []]` when no mention matched any cue class — the signal
     * for escalation. A mention that *did* match contributes both its
     * requirement and the phrase that produced it, so the evidence on the result
     * is the actual text that decided the spend.
     *
     * @param  array<int, string>  $mentions
     * @return array{0: ?CoverLetterRequirement, 1: array<int, string>}
     */
    protected function classifyMentions(array $mentions): array
    {
        /** @var array<string, array<int, string>> $evidenceByRequirement */
        $evidenceByRequirement = [];

        foreach ($mentions as $window) {
            foreach (self::CUE_ORDER as $configKey => $requirement) {
                $matched = $this->firstPhraseIn($window, $configKey);

                if ($matched === null) {
                    continue;
                }

                $evidenceByRequirement[$requirement->value][] = $matched;

                // First class to claim the mention owns it: the order in
                // CUE_ORDER is what resolves "not required but encouraged" and
                // "without a cover letter will not be considered".
                break;
            }
        }

        foreach (self::AGGREGATE_PRECEDENCE as $requirement) {
            if (array_key_exists($requirement->value, $evidenceByRequirement)) {
                return [
                    $requirement,
                    array_values(array_unique($evidenceByRequirement[$requirement->value])),
                ];
            }
        }

        return [null, []];
    }

    /** The first phrase from cue class `$configKey` present in `$window`. */
    protected function firstPhraseIn(string $window, string $configKey): ?string
    {
        foreach ($this->phrases($configKey) as $phrase) {
            if (str_contains($window, $phrase)) {
                return $phrase;
            }
        }

        return null;
    }

    /**
     * A configured phrase list, normalized the same way the description is and
     * longest-first.
     *
     * Longest-first is what makes the lists forgiving to edit: "not required
     * but" and "not required" can both sit in their classes without the shorter
     * one shadowing the longer, and the phrase quoted as evidence is the most
     * specific one that actually matched.
     *
     * @return array<int, string>
     */
    protected function phrases(string $configKey): array
    {
        $configured = config("cover_letter.detection.{$configKey}", []);

        if (! is_array($configured)) {
            return [];
        }

        $phrases = [];

        foreach ($configured as $entry) {
            // The sentence markers normalization introduces are stripped from the
            // *ends* of a phrase: an operator copying "Don't send a cover letter!"
            // out of a posting means the words, not the exclamation mark, and a
            // trailing marker would stop the phrase matching mid-sentence text.
            $phrase = is_string($entry)
                ? trim($this->normalize($entry), ' '.self::SENTENCE_BREAK)
                : '';

            if ($phrase !== '') {
                $phrases[$phrase] = mb_strlen($phrase);
            }
        }

        arsort($phrases);

        return array_keys($phrases);
    }

    /* ---------------------------------------------------------------------
     | The paid pass: one cheap classification, with one stricter retry
     | -------------------------------------------------------------------- */

    /**
     * Ask a model what the ambiguous mentions mean.
     *
     * Same retry contract as scoring and resume tailoring
     * (`detection.max_attempts`: one normal attempt, then one carrying a
     * stricter instruction naming the failure) sitting above the router's own
     * same-tier model fallback (Requirement 4.5). Where this differs from its
     * siblings is the ending: an exhausted budget produces Requirement 7.2's
     * default rather than an exception.
     *
     * @param  array<int, string>  $mentions
     */
    protected function classifyWithModel(JobListing $listing, array $mentions): CoverLetterDetection
    {
        $maxAttempts = max(1, (int) config('cover_letter.detection.max_attempts', 2));
        $schema = $this->schema();
        $excerpt = $this->excerpt($mentions);
        $correction = null;

        /** @var array<int, array<string, mixed>> $attempts */
        $attempts = [];

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $result = $this->router->complete(
                    self::TASK_COVER_LETTER_DETECTION,
                    $this->messages($listing, $excerpt, $correction),
                    // Salary and complexity are carried for the usage record's
                    // sake, but the tier is pinned below: this is a label on a
                    // sentence, not work whose stakes vary with the role.
                    ModelTierContext::fromSignals(
                        salaryMin: $listing->salary_min,
                        salaryMax: $listing->salary_max,
                        currency: $listing->currency,
                        jdLength: mb_strlen($excerpt),
                    ),
                    $schema,
                    // Attributes the spend to the posting it was spent on (Req 4.6).
                    $listing,
                    $this->tierOverride(),
                );

                return $this->fromModel($result, $attempt, $listing);
            } catch (ModelRouterException|MalformedCoverLetterDetectionPayload $e) {
                $attempts[] = $record = [
                    'attempt' => $attempt,
                    'task_type' => self::TASK_COVER_LETTER_DETECTION,
                    'job_listing_id' => $listing->getKey(),
                    'reason' => $e->getMessage(),
                ];

                Log::warning('Cover letter detection attempt failed.', $record);

                if ($attempt >= $maxAttempts) {
                    break;
                }

                $correction = $this->correctionNote($schema, $e->getMessage());
            }
        }

        // Requirement 7.2's default, reached because nothing better is known.
        // Deliberately not an exception: the posting never plainly asked for a
        // letter (or the keyword pass would have answered), so skipping is both
        // the safe answer and the likely-correct one.
        $detection = new CoverLetterDetection(
            requirement: CoverLetterRequirement::NotRequested,
            method: CoverLetterDetectionMethod::ModelFailed,
            evidence: ['detection failed after '.count($attempts).' attempt(s); defaulting to no cover letter'],
            confidence: 0.0,
            attempts: count($attempts),
        );

        Log::error('Cover letter detection exhausted its attempts; defaulting to no cover letter.', [
            'job_listing_id' => $listing->getKey(),
            'attempts' => $attempts,
        ] + $detection->context());

        return $detection;
    }

    /**
     * Turn a successful completion into a decision.
     *
     * @throws MalformedCoverLetterDetectionPayload when the response does not answer the question
     */
    protected function fromModel(
        ModelCompletionResult $result,
        int $attempt,
        JobListing $listing,
    ): CoverLetterDetection {
        $label = $result->parsedJson['requirement'] ?? null;
        $requirement = is_string($label)
            ? CoverLetterRequirement::tryFrom(trim(mb_strtolower($label)))
            : null;

        if ($requirement === null) {
            throw new MalformedCoverLetterDetectionPayload(sprintf(
                'Cover letter detection returned an unusable `requirement` (%s); expected one of: %s.',
                is_string($label) ? '"'.Str::limit($label, 40).'"' : get_debug_type($label),
                implode(', ', array_column(CoverLetterRequirement::cases(), 'value')),
            ));
        }

        $rawConfidence = $result->parsedJson['confidence'] ?? null;

        if (! is_int($rawConfidence) && ! is_float($rawConfidence)) {
            throw new MalformedCoverLetterDetectionPayload(
                'Cover letter detection returned a non-numeric `confidence` ('
                .get_debug_type($rawConfidence).').'
            );
        }

        $confidence = max(0.0, min(1.0, (float) $rawConfidence));
        $evidence = $result->parsedJson['evidence'] ?? null;
        $evidence = is_string($evidence) && trim($evidence) !== ''
            ? [Str::limit(trim($evidence), 200)]
            : [];

        $threshold = $this->floatConfig('min_model_confidence', 0.6);

        // Requirement 7.2's asymmetry, applied to the model's own uncertainty: a
        // hesitant "required" is downgraded, a hesitant "not requested" is not,
        // because the second one already agrees with the default. The original
        // answer survives in the evidence so the downgrade is auditable rather
        // than invisible.
        if ($requirement->wantsCoverLetter() && $confidence < $threshold) {
            $evidence[] = sprintf(
                'model answered "%s" with confidence %.2f, below the %.2f threshold; downgraded',
                $requirement->value,
                $confidence,
                $threshold,
            );

            $requirement = CoverLetterRequirement::NotRequested;
        }

        $detection = new CoverLetterDetection(
            requirement: $requirement,
            method: CoverLetterDetectionMethod::Model,
            evidence: $evidence,
            confidence: $confidence,
            modelUsed: $result->modelUsed,
            tierUsed: $result->tierUsed,
            attempts: $attempt,
        );

        Log::info('Cover letter requirement classified by model.', [
            'job_listing_id' => $listing->getKey(),
        ] + $detection->context());

        return $detection;
    }

    /**
     * The pinned tier (config `detection.tier_override`), or null to let the
     * router's ordinary salary/complexity selection run.
     *
     * Pinned to the cheap tier by default. The full reasoning is in
     * config/cover_letter.php; the short version is that
     * {@see ModelRouterService}'s override exists for *capability* rather than
     * quality, and the capability wanted here is "cheap enough to be worth
     * asking" — escalating a three-way label because the role pays well would
     * spend premium tokens on the least demanding question in the pipeline.
     */
    protected function tierOverride(): ?string
    {
        $tier = config('cover_letter.detection.tier_override');

        return is_string($tier) && trim($tier) !== '' ? trim($tier) : null;
    }

    /**
     * The mention windows, joined and bounded.
     *
     * The prompt carries these instead of the posting: only text near a mention
     * can bear on the question, and a posting's benefits section is both
     * irrelevant and the bulk of its tokens.
     *
     * @param  array<int, string>  $mentions
     */
    protected function excerpt(array $mentions): string
    {
        $limit = (int) config('cover_letter.detection.max_excerpt_chars', 2000);
        $joined = implode("\n---\n", $mentions);

        return $limit > 0 ? Str::limit($joined, $limit, ' …[truncated]') : $joined;
    }

    /**
     * @return array<int, array<string, string>>
     */
    protected function messages(JobListing $listing, string $excerpt, ?string $correction): array
    {
        $system = 'You classify what a job posting asks of an applicant. You are given short excerpts '
            .'from one posting, each containing a mention of a cover letter, with punctuation and '
            .'capitalisation removed. Decide whether that posting wants a cover letter. Answer '
            .'"required" only if a cover letter is mandatory, "optional" if it is invited, welcomed or '
            .'encouraged without being mandatory, and "not_requested" if the posting does not ask for '
            .'one or asks applicants not to send one. A mention alone is not a request: text that '
            .'merely refers to cover letters in passing, or that describes a different role or a '
            .'different document, is "not_requested". When the excerpts do not settle it, answer '
            .'"not_requested" with a low confidence rather than guessing. Respond with JSON matching '
            .'the requested schema and nothing else.';

        $user = "Does this posting ask the applicant for a cover letter?\n\n"
            ."Title: {$listing->title}\n"
            ."Company: {$listing->company}\n\n"
            ."=== EXCERPTS MENTIONING A COVER LETTER ===\n"
            .$excerpt."\n\n"
            ."Return:\n"
            ."- requirement: one of required, optional, not_requested.\n"
            ."- evidence: the words from the excerpts that decided it, quoted briefly.\n"
            .'- confidence: 0 to 1, how sure you are.';

        if ($correction !== null) {
            $system .= "\n\n".$correction;
        }

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];
    }

    /**
     * The schema the classifier is held to.
     *
     * An enum rather than a boolean, matching {@see CoverLetterRequirement}, so
     * the model cannot answer "yes, sort of" and leave the caller to interpret
     * it — and so the three-state result has one vocabulary end to end.
     *
     * @return array<string, mixed>
     */
    protected function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['requirement', 'evidence', 'confidence'],
            'properties' => [
                'requirement' => [
                    'type' => 'string',
                    'enum' => array_column(CoverLetterRequirement::cases(), 'value'),
                ],
                'evidence' => ['type' => 'string'],
                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
            ],
        ];
    }

    /**
     * The stricter instruction the one retry carries.
     *
     * Restates the schema inline for the same reason its siblings do: a provider
     * that silently dropped `response_format` never showed the model a schema at
     * all, so naming the failure without restating the contract asks it to guess
     * twice.
     *
     * @param  array<string, mixed>  $schema
     */
    protected function correctionNote(array $schema, string $failure): string
    {
        return 'Your previous response could not be used: '.Str::limit($failure, 300)."\n\n"
            .'Return ONLY valid JSON matching this schema. No prose, no explanation, no markdown '
            ."code fence. Every property is required and must use the exact name and type shown, and "
            ."`requirement` must be exactly one of the listed values:\n"
            .(string) json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    /* ---------------------------------------------------------------------
     | Small shared helpers
     | -------------------------------------------------------------------- */

    /** Requirement 7.2's default, with a note on why nothing contradicted it. */
    protected function absent(JobListing $listing, string $reason): CoverLetterDetection
    {
        $detection = new CoverLetterDetection(
            requirement: CoverLetterRequirement::NotRequested,
            method: CoverLetterDetectionMethod::Absent,
            evidence: [$reason],
            confidence: $this->floatConfig('absent_confidence', 0.95),
        );

        Log::info('No cover letter requested; skipping cover letter generation.', [
            'job_listing_id' => $listing->getKey(),
            'reason' => $reason,
        ] + $detection->context());

        return $detection;
    }

    /** A configured 0-1 figure, clamped so a mis-typed config cannot leave the range. */
    protected function floatConfig(string $key, float $default): float
    {
        $value = config("cover_letter.detection.{$key}", $default);

        return is_numeric($value) ? max(0.0, min(1.0, (float) $value)) : $default;
    }
}

<?php

namespace App\Services\Scoring;

use App\Enums\RecommendedAction;
use App\Exceptions\JobScoringException;
use App\Exceptions\ModelRouterException;
use App\Models\JobListing;
use App\Models\UserProfile;
use App\Services\ModelCompletionResult;
use App\Services\ModelRouterService;
use App\Services\ModelTierContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The two-prompt scoring pipeline of Requirement 5.1, as two pure-ish calls:
 * {@see promptFitAnalysis()} works out what the posting wants and what the
 * candidate is missing, and {@see promptRatingDecision()} turns that analysis —
 * and nothing else — into a 1-5 rating with a rationale and a recommended
 * action.
 *
 * Why two calls instead of one: the rating is a judgement about an analysis, so
 * splitting them keeps the expensive reasoning separate from the extraction,
 * lets each be routed to a different model tier (prompt 2 is routed with the
 * requirement count and skill-gap size prompt 1 discovered, which is exactly
 * the complexity signal Requirement 4.4 wants and which does not exist before
 * prompt 1 has run), and makes a bad rating diagnosable — the stored analysis
 * shows whether the model misread the posting or misjudged a correct reading.
 *
 * What this class deliberately does not do: it never touches `job_scores`,
 * never moves `pipeline_stage`, and never decides whether to auto-apply.
 * Persistence, versioning by `attempt_number` (Requirement 5.6) and the
 * `store_only`/`needs_review` fallback all belong to the queued
 * `ScoreJobListing` job (task 10.3). This class returns a value object or
 * throws {@see JobScoringException}; that is its whole contract, which is what
 * makes it testable against a faked {@see ModelRouterService} with no database.
 *
 * Retry contract (Requirement 5.5): each prompt gets
 * `config('scoring.max_attempts')` attempts — one normal, then one carrying a
 * stricter "return ONLY valid JSON matching this schema" instruction plus the
 * schema itself and a note about what went wrong. Both a router-level parse
 * failure (nothing decodable came back) and a semantically wrong payload (a
 * rating of 9, a blank rationale, an unknown recommended action) trigger it.
 * When the retry also fails the service gives up loudly with the full attempt
 * history attached, and the caller — not this class — falls back to
 * `store_only` + manual review.
 *
 * Note the retry sits *above* the router's own same-tier model fallback
 * (Requirement 4.5): by the time a failure surfaces here, every model in the
 * tier has already been asked with the original wording, so re-asking is only
 * worth it because the wording changes.
 */
class JobScoringService
{
    /** Task types recorded in `model_usage_logs` (design.md §1, Requirement 4.6). */
    public const TASK_FIT_ANALYSIS = 'jd_fit_analysis';

    public const TASK_RATING_DECISION = 'jd_rating';

    public function __construct(private readonly ModelRouterService $router)
    {
    }

    /**
     * Prompt 1: extract the posting's requirements and diff them against the
     * candidate's profile (Requirement 5.1).
     *
     * @throws JobScoringException when every allowed attempt fails
     */
    public function promptFitAnalysis(JobListing $listing, UserProfile $profile): FitAnalysis
    {
        $description = $this->descriptionFor($listing);
        $profileSummary = $this->profileSummary($profile);

        /** @var FitAnalysis $analysis */
        $analysis = $this->runPrompt(
            JobScoringException::PROMPT_FIT_ANALYSIS,
            self::TASK_FIT_ANALYSIS,
            // No fit signals exist yet, so routing rests on salary when the
            // listing has it and on JD size otherwise.
            ModelTierContext::fromSignals(
                salaryMin: $listing->salary_min,
                salaryMax: $listing->salary_max,
                currency: $listing->currency,
                jdLength: mb_strlen($description),
            ),
            $this->fitAnalysisSchema(),
            $listing,
            fn (?string $correction): array => $this->fitAnalysisMessages($description, $profileSummary, $correction),
            fn (array $parsed, ModelCompletionResult $result, int $attempt): FitAnalysis
                => $this->toFitAnalysis($parsed, $result, $attempt),
        );

        Log::info('Job fit analysis completed.', [
            'job_listing_id' => $listing->getKey(),
            'user_id' => $profile->user_id,
        ] + $analysis->context());

        return $analysis;
    }

    /**
     * Prompt 2: rate the fit analysis 1-5 with a rationale and a recommended
     * action (Requirement 5.1).
     *
     * Takes the analysis, not the job description: the rating must be a
     * judgement of prompt 1's reading of the posting, so what was rated is
     * exactly what gets persisted as `fit_analysis` and shown to the user. Handing
     * the JD in again would let prompt 2 reach conclusions the stored analysis
     * does not explain.
     *
     * @throws JobScoringException when every allowed attempt fails
     */
    public function promptRatingDecision(JobListing $listing, FitAnalysis $analysis): RatingDecision
    {
        $payload = $this->encode($analysis->toArray());

        /** @var RatingDecision $decision */
        $decision = $this->runPrompt(
            JobScoringException::PROMPT_RATING_DECISION,
            self::TASK_RATING_DECISION,
            // Now the real complexity signals exist: how much the posting asks
            // for and how much of it the candidate is missing (Requirement 4.4).
            // `jdLength` carries the size of the analysis being judged, since
            // that is this prompt's actual input.
            ModelTierContext::fromSignals(
                salaryMin: $listing->salary_min,
                salaryMax: $listing->salary_max,
                currency: $listing->currency,
                jdLength: mb_strlen($payload),
                requirementCount: $analysis->requirementCount(),
                skillGapSize: $analysis->skillGapSize(),
            ),
            $this->ratingDecisionSchema(),
            $listing,
            fn (?string $correction): array => $this->ratingDecisionMessages($listing, $payload, $correction),
            fn (array $parsed, ModelCompletionResult $result, int $attempt): RatingDecision
                => $this->toRatingDecision($parsed, $result, $attempt),
        );

        Log::info('Job rating decision completed.', [
            'job_listing_id' => $listing->getKey(),
        ] + $decision->context());

        return $decision;
    }

    /**
     * Run one prompt with the Requirement 5.5 retry.
     *
     * Shared by both prompts because the retry rule is a property of the
     * pipeline, not of either prompt: a second, stricter attempt then a typed
     * failure. `$messages` receives the correction note to embed (null on the
     * first attempt) and `$mapper` converts a decoded payload into a value
     * object, throwing {@see MalformedScoringPayload} when the payload decodes
     * but doesn't say what it must.
     *
     * @param callable(?string): array<int, array<string, string>>                          $messages
     * @param callable(array<mixed>, ModelCompletionResult, int): (FitAnalysis|RatingDecision) $mapper
     * @param array<string, mixed>                                                         $schema
     *
     * @throws JobScoringException
     */
    protected function runPrompt(
        string $prompt,
        string $taskType,
        ModelTierContext $context,
        array $schema,
        JobListing $listing,
        callable $messages,
        callable $mapper,
    ): FitAnalysis|RatingDecision {
        $maxAttempts = max(1, (int) config('scoring.max_attempts', 2));

        /** @var array<int, array<string, mixed>> $attempts */
        $attempts = [];
        $lastFailure = null;
        $correction = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $result = $this->router->complete(
                    $taskType,
                    $messages($correction),
                    $context,
                    $schema,
                    // Attributes token spend to the listing being scored (Req 4.6).
                    $listing,
                );

                return $mapper($result->parsedJson, $result, $attempt);
            } catch (ModelRouterException | MalformedScoringPayload $e) {
                $lastFailure = $e;
                $parseFailure = $e instanceof MalformedScoringPayload
                    || $this->isRouterParseFailure($e);

                $attempts[] = $record = [
                    'attempt' => $attempt,
                    'prompt' => $prompt,
                    'task_type' => $taskType,
                    'job_listing_id' => $listing->getKey(),
                    'parse_failure' => $parseFailure,
                    'reason' => $e->getMessage(),
                ];

                Log::warning('Scoring prompt attempt failed.', $record);

                if ($attempt >= $maxAttempts) {
                    break;
                }

                // What the next attempt is told: what went wrong, plus the
                // schema restated as an instruction rather than only as a
                // `response_format` the model may have ignored.
                $correction = $this->correctionNote($schema, $e->getMessage());
            }
        }

        $exception = JobScoringException::promptFailed($prompt, $taskType, $attempts, $lastFailure);

        Log::error($exception->getMessage(), $exception->context);

        throw $exception;
    }

    /**
     * Did a router failure come from unusable *content* rather than a failed
     * call?
     *
     * Structural rather than message-matching: `unparseableJson()` is the only
     * ModelRouterException factory that records the offending completion under
     * `content`, and the exhausted-candidates wrapper keeps the underlying
     * failure as `previous`. Used only to label the attempt history — the retry
     * happens for either kind of failure, since a stricter prompt costs one
     * call and a transient provider error may well have cleared by then.
     */
    protected function isRouterParseFailure(ModelRouterException $e): bool
    {
        foreach ([$e, $e->getPrevious()] as $candidate) {
            if ($candidate instanceof ModelRouterException
                && array_key_exists('content', $candidate->context)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The stricter formatting instruction Requirement 5.5 asks for.
     *
     * Names the failure and restates the schema inline. Both matter: a model
     * that returned prose needs to be told the output was unusable, and a model
     * whose provider silently dropped `response_format` never saw the schema at
     * all.
     *
     * @param array<string, mixed> $schema
     */
    protected function correctionNote(array $schema, string $failure): string
    {
        return "Your previous response could not be used: ".Str::limit($failure, 300)."\n\n"
            ."Return ONLY valid JSON matching this schema. No prose, no explanation, "
            ."no markdown code fence, no trailing commentary. Every property is required "
            ."and must use the exact name and type shown:\n"
            .$this->encode($schema);
    }

    /* ---------------------------------------------------------------------
     | Prompt 1: fit analysis
     | -------------------------------------------------------------------- */

    /**
     * @return array<int, array<string, string>>
     */
    protected function fitAnalysisMessages(string $description, string $profileSummary, ?string $correction): array
    {
        $system = 'You are a technical recruiter analysing how well a candidate fits a job posting. '
            .'Work only from the posting and the candidate profile given to you: never assume a skill '
            .'the profile does not evidence, and never treat a requirement the posting does not state. '
            .'A skill counts as matched only if the profile shows it explicitly or shows something that '
            .'plainly subsumes it. Respond with JSON matching the requested schema and nothing else.';

        $user = "Analyse the fit between this job posting and this candidate.\n\n"
            ."Return:\n"
            ."- requiredSkills: the skills, tools and qualifications the posting requires or asks for.\n"
            ."- seniority: the seniority the posting targets (e.g. intern, junior, mid, senior, staff, "
            ."principal, lead, manager), or \"unspecified\" if the posting does not say.\n"
            ."- matchedSkills: the entries of requiredSkills the candidate's profile evidences.\n"
            ."- missingSkills: the entries of requiredSkills the candidate's profile does not evidence.\n"
            ."- gapSummary: two or three sentences on the most important gaps and strengths, written for "
            ."the candidate to read.\n\n"
            ."Every entry of matchedSkills and missingSkills must also appear in requiredSkills, and no "
            ."skill may appear in both.\n\n"
            ."=== JOB POSTING ===\n"
            ."{$description}\n\n"
            ."=== CANDIDATE PROFILE ===\n"
            ."{$profileSummary}";

        return $this->messages($system, $user, $correction);
    }

    /**
     * @return array<string, mixed>
     */
    protected function fitAnalysisSchema(): array
    {
        $stringList = ['type' => 'array', 'items' => ['type' => 'string']];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['requiredSkills', 'seniority', 'matchedSkills', 'missingSkills', 'gapSummary'],
            'properties' => [
                'requiredSkills' => $stringList,
                'seniority' => ['type' => 'string'],
                'matchedSkills' => $stringList,
                'missingSkills' => $stringList,
                'gapSummary' => ['type' => 'string'],
            ],
        ];
    }

    /**
     * @param array<mixed> $parsed
     */
    protected function toFitAnalysis(array $parsed, ModelCompletionResult $result, int $attempt): FitAnalysis
    {
        return new FitAnalysis(
            requiredSkills: $this->stringList($parsed, 'requiredSkills'),
            // Absent seniority is not a parse failure: plenty of postings never
            // state one, and the schema's own instruction is to say so.
            seniority: $this->optionalString($parsed, 'seniority', 'unspecified'),
            matchedSkills: $this->stringList($parsed, 'matchedSkills'),
            missingSkills: $this->stringList($parsed, 'missingSkills'),
            gapSummary: $this->requiredString($parsed, 'gapSummary'),
            rawOutput: $result->content,
            modelUsed: $result->modelUsed,
            tierUsed: $result->tierUsed,
            attempts: $attempt,
        );
    }

    /* ---------------------------------------------------------------------
     | Prompt 2: rating decision
     | -------------------------------------------------------------------- */

    /**
     * @return array<int, array<string, string>>
     */
    protected function ratingDecisionMessages(JobListing $listing, string $analysisJson, ?string $correction): array
    {
        [$min, $max] = $this->starBounds();
        $threshold = $this->autoApplyThreshold();

        $system = "You convert a job-fit analysis into a rating. Judge only the analysis you are given — "
            ."do not speculate about the posting or the candidate beyond it. Be strict: {$max} stars means "
            ."the candidate meets essentially every requirement, and a rating at or above {$threshold} "
            ."means an application should be sent without a human reading it first. Respond with JSON "
            ."matching the requested schema and nothing else.";

        $user = "Rate this candidate's fit for the role \"{$listing->title}\" at {$listing->company}.\n\n"
            ."Return:\n"
            ."- stars: an integer from {$min} to {$max}.\n"
            ."- rationale: one or two sentences justifying the rating, referring to specific matched or "
            ."missing requirements from the analysis.\n"
            .'- recommendedAction: "'.RecommendedAction::AutoApply->value.'" if the fit is strong enough '
            ."to apply automatically (normally {$threshold} stars or more), otherwise \""
            .RecommendedAction::StoreOnly->value."\".\n\n"
            ."=== FIT ANALYSIS ===\n"
            .$analysisJson;

        return $this->messages($system, $user, $correction);
    }

    /**
     * @return array<string, mixed>
     */
    protected function ratingDecisionSchema(): array
    {
        [$min, $max] = $this->starBounds();

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['stars', 'rationale', 'recommendedAction'],
            'properties' => [
                'stars' => [
                    'type' => 'integer',
                    'minimum' => $min,
                    'maximum' => $max,
                ],
                'rationale' => ['type' => 'string'],
                'recommendedAction' => [
                    'type' => 'string',
                    'enum' => array_column(RecommendedAction::cases(), 'value'),
                ],
            ],
        ];
    }

    /**
     * @param array<mixed> $parsed
     */
    protected function toRatingDecision(array $parsed, ModelCompletionResult $result, int $attempt): RatingDecision
    {
        [$min, $max] = $this->starBounds();

        $stars = $parsed['stars'] ?? null;

        // A float that is a whole number (5.0) is accepted, since JSON has one
        // number type and some models emit it; 4.5 is not, because a rating
        // between two stars is a different answer than either of them.
        if (! is_int($stars) && ! (is_float($stars) && $stars === floor($stars))) {
            throw new MalformedScoringPayload(
                'Rating decision is missing an integer `stars` value (got '.$this->describe($stars).').'
            );
        }

        $stars = (int) $stars;

        if ($stars < $min || $stars > $max) {
            throw new MalformedScoringPayload(
                "Rating decision returned {$stars} stars, outside the allowed {$min}-{$max} range."
            );
        }

        $action = $parsed['recommendedAction'] ?? null;
        $recommendedAction = is_string($action) ? RecommendedAction::tryFrom(trim($action)) : null;

        if ($recommendedAction === null) {
            throw new MalformedScoringPayload(
                'Rating decision returned an unknown `recommendedAction` ('.$this->describe($action).').'
            );
        }

        return new RatingDecision(
            stars: $stars,
            rationale: $this->requiredString($parsed, 'rationale'),
            recommendedAction: $recommendedAction,
            rawOutput: $result->content,
            modelUsed: $result->modelUsed,
            tierUsed: $result->tierUsed,
            attempts: $attempt,
        );
    }

    /* ---------------------------------------------------------------------
     | Prompt input preparation
     | -------------------------------------------------------------------- */

    /**
     * The description text prompt 1 scores.
     *
     * Truncated to `scoring.max_description_chars`, and an empty description is
     * a failure rather than a prompt: scoring a listing with nothing to score
     * would produce a confident rating of thin air. Enrichment (Requirement
     * 3.4) is what fills this in, so a listing that reaches here empty is a
     * pipeline ordering bug, not a model problem.
     *
     * @throws JobScoringException
     */
    protected function descriptionFor(JobListing $listing): string
    {
        $description = trim((string) $listing->description);

        if ($description === '') {
            throw JobScoringException::promptFailed(
                JobScoringException::PROMPT_FIT_ANALYSIS,
                self::TASK_FIT_ANALYSIS,
                [[
                    'attempt' => 0,
                    'parse_failure' => false,
                    'job_listing_id' => $listing->getKey(),
                    'reason' => 'The job listing has no description to score; it needs enrichment first.',
                ]]
            );
        }

        return $this->truncate($description, 'max_description_chars', 20000);
    }

    /**
     * The candidate, as compact JSON.
     *
     * A serialized subset rather than the whole row: `user_profiles` carries
     * ids, timestamps and a full resume dump that cost tokens without changing
     * a fit verdict. The raw resume text is included last and on its own,
     * smaller budget — the structured fields are the signal, the text is there
     * to catch what the resume parser flattened.
     */
    protected function profileSummary(UserProfile $profile): string
    {
        $summary = array_filter([
            'skills' => $profile->skills,
            'location' => $profile->location,
            'suggestedRoles' => $profile->suggested_roles,
            'experience' => $profile->experience,
            'education' => $profile->education,
            'keywords' => $profile->parsed_keywords,
        ], fn ($value) => $value !== null && $value !== '' && $value !== []);

        $json = $this->truncate($this->encode($summary), 'max_profile_chars', 12000);

        $resumeText = trim((string) $profile->resume_text);

        if ($resumeText !== '') {
            $json .= "\n\nRESUME TEXT (excerpt):\n"
                .$this->truncate($resumeText, 'max_resume_text_chars', 6000);
        }

        return $json;
    }

    /**
     * @return array<int, array<string, string>>
     */
    protected function messages(string $system, string $user, ?string $correction): array
    {
        if ($correction !== null) {
            // Appended to the system message so the correction carries the same
            // weight as the original instructions rather than reading as one
            // more thing the user asked for.
            $system .= "\n\n".$correction;
        }

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];
    }

    /* ---------------------------------------------------------------------
     | Payload validation helpers
     | -------------------------------------------------------------------- */

    /**
     * A list of non-empty strings, or [] when the key is absent.
     *
     * An absent or empty list is legitimate — a posting can state no
     * requirements, and a candidate can be missing none — so this never
     * throws. Non-string entries are dropped rather than stringified, since a
     * nested object in a skill list means the model answered a different
     * question and the surviving strings still describe the fit.
     *
     * @param array<mixed> $parsed
     *
     * @return array<int, string>
     */
    protected function stringList(array $parsed, string $key): array
    {
        $value = $parsed[$key] ?? [];

        if (! is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $entry) {
            if (is_string($entry) && trim($entry) !== '') {
                $strings[] = trim($entry);
            }
        }

        return array_values(array_unique($strings));
    }

    /**
     * @param array<mixed> $parsed
     *
     * @throws MalformedScoringPayload when the field is missing or blank
     */
    protected function requiredString(array $parsed, string $key): string
    {
        $value = $parsed[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new MalformedScoringPayload(
                "Scoring output is missing a usable `{$key}` string (got ".$this->describe($value).').'
            );
        }

        return trim($value);
    }

    /**
     * @param array<mixed> $parsed
     */
    protected function optionalString(array $parsed, string $key, string $default): string
    {
        $value = $parsed[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }

    /** A value's shape, for a failure message that must not leak a whole payload. */
    protected function describe(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_string($value) => $value === '' ? 'an empty string' : '"'.Str::limit($value, 60).'"',
            is_bool($value) => $value ? 'true' : 'false',
            is_array($value) => 'an array',
            default => (string) $value,
        };
    }

    /* ---------------------------------------------------------------------
     | Config accessors
     | -------------------------------------------------------------------- */

    /**
     * @return array{0: int, 1: int}
     */
    protected function starBounds(): array
    {
        $min = (int) config('scoring.stars.min', 1);
        $max = (int) config('scoring.stars.max', 5);

        // A reversed or collapsed range in config would make every rating
        // invalid and fail every listing, so it degrades to the documented
        // default instead.
        return $max > $min ? [$min, $max] : [1, 5];
    }

    /**
     * The threshold the prompt is told about, so the model's recommendation and
     * the pipeline's own decision are calibrated to the same number
     * (Requirements 5.2, 5.3). The decision itself stays with task 10.3.
     */
    protected function autoApplyThreshold(): int
    {
        [$min, $max] = $this->starBounds();

        $threshold = (int) config('scoring.auto_apply_star_threshold', 4);

        return max($min, min($max, $threshold));
    }

    protected function truncate(string $value, string $configKey, int $default): string
    {
        $limit = (int) config("scoring.{$configKey}", $default);

        if ($limit <= 0) {
            return $value;
        }

        return Str::limit($value, $limit, ' …[truncated]');
    }

    /** @param array<mixed> $value */
    protected function encode(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}

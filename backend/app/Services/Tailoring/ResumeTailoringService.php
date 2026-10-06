<?php

namespace App\Services\Tailoring;

use App\Exceptions\ModelRouterException;
use App\Exceptions\ResumeTailoringException;
use App\Models\JobListing;
use App\Models\UserProfile;
use App\Services\ModelCompletionResult;
use App\Services\ModelRouterService;
use App\Services\ModelTierContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The LLM half of resume tailoring (Requirement 6.1): given a posting and a
 * profile, produce the structured content array
 * {@see \App\Services\Latex\LatexRenderService::renderResume()} consumes.
 *
 * ## Why this lives in `Services\Tailoring` and not `Services\Latex`
 *
 * Nothing here knows what LaTeX is, except well enough to refuse it. It writes
 * no `.tex`, runs no engine, and escapes nothing — escaping is a property of the
 * template's echo format ({@see \App\Services\Latex\LatexEscaper}) and stays
 * there. What this class actually is, is a prompt plus a JSON schema plus a
 * bounded retry plus a merge, which makes it a sibling of
 * {@see \App\Services\Scoring\JobScoringService} rather than of the renderer.
 * The renderer's own docblock draws the same line from the other side: it is
 * "deliberately unaware of the other half". The namespace also has somewhere
 * obvious to put the cover-letter equivalent (task 12.3), which shares this
 * class's contract and none of the renderer's process handling.
 *
 * ## The model never restates a fact
 *
 * This is the design's central constraint (design.md §"LaTeX resume tailoring",
 * Requirement 6.6) and it is enforced structurally rather than by asking
 * nicely. The document's factual skeleton — employer names, job titles, date
 * ranges, institutions, degrees, the candidate's name and contact details — is
 * projected from {@see UserProfile} *before* any model call, and the model's
 * response can only contribute:
 *
 *  - `summary`: the tailored professional summary,
 *  - `headline`: the target title shown under the name,
 *  - `experience[].bullets`: reworded achievement bullets, bound to a role by
 *    its *index* in the projection, never by naming the employer,
 *  - `emphasizedSkills`: an ordering hint over skills the profile already
 *    claims; an entry the profile does not claim is dropped, not added.
 *
 * A model cannot therefore rename an employer, shift a date, or invent a
 * degree — those values are never in its output at all, so there is nothing to
 * fabricate. That is also precisely what makes the fabrication guard of task
 * 11.5 possible: because employer/title/date tokens have exactly one source,
 * that guard can diff the rendered document against
 * {@see TailoredResumeContent::$facts} and treat *any* mismatch as a real
 * finding. Were the model allowed to restate facts, every diff would need a
 * fuzzy-match tolerance and the guard would degrade into a heuristic.
 *
 * Bullets are the one place model wording touches employment history, which is
 * why they are bound by index and why the guard still checks the rendered
 * result rather than trusting this merge.
 *
 * ## Retry contract
 *
 * The same contract as the scoring pipeline (Requirement 5.5, applied here):
 * `config('latex.tailoring.max_attempts')` attempts — one normal, then one
 * carrying a stricter "return ONLY valid JSON matching this schema" instruction
 * plus the schema and a note on what went wrong. Both a router-level parse
 * failure and a semantically unusable payload (blank summary, bullets for no
 * real role, LaTeX in a prose field) trigger it. When the retry also fails the
 * service throws {@see ResumeTailoringException} and the caller — not this
 * class — decides what that means for the listing.
 *
 * As in scoring, this retry sits *above* the router's own same-tier model
 * fallback (Requirement 4.5): by the time a failure surfaces here every model
 * in the tier has already been asked with the original wording, so re-asking is
 * only worth it because the wording changes.
 *
 * And there is a *third* budget above this one, which is easy to confuse with
 * it: `config('pipeline.latex_retry_max')`, spent by
 * {@see ResumeTailoringPipeline} when a response this class considered perfectly
 * good produced a document the LaTeX engine rejected. That loop re-enters here
 * through {@see tailorWithCorrection()}, so each of its retries gets a fresh
 * `latex.tailoring.max_attempts` allowance. Nothing in this class knows how many
 * times it has been called, on purpose — a compile failure is not evidence that
 * the model cannot follow the response contract, which is the only thing the
 * budget here measures.
 *
 * ## Scope
 *
 * Out of scope here, on purpose: driving the compile-failure corrective loop
 * (Requirement 6.4 — that lives in {@see ResumeTailoringPipeline}; this class
 * only exposes {@see tailorWithCorrection()} for it to call), the fabrication
 * guard (Requirement 6.6, task 11.5), and persistence/upload (task 11.7). This
 * class returns a value object or throws; that is its whole contract, which is
 * what lets it be tested against a faked router with no database and no TeX
 * engine.
 */
class ResumeTailoringService
{
    /** Task type recorded in `model_usage_logs` (design.md §1, Requirement 4.6). */
    public const TASK_RESUME_TAILOR = 'resume_tailor';

    /**
     * A TeX control sequence: a backslash followed by a command name or by any
     * of the characters LaTeX lets you escape.
     *
     * The backslash is the whole discriminator, and deliberately the only one.
     * `%`, `&`, `$`, `#` and `_` are ordinary characters in prose a resume
     * genuinely contains ("cut p99 latency by 40%", "R&D", "$2M ARR") and the
     * template escapes each of them by construction, so treating them as
     * suspicious would reject good bullets to no benefit. A backslash, by
     * contrast, has no business in a sentence about a job — it is either
     * `\textbf{}` (a model ignoring the contract) or `\input{/etc/passwd}` (a
     * model being steered by a hostile job description, which is untrusted text
     * this prompt necessarily includes).
     */
    private const LATEX_COMMAND_PATTERN = '/\\\\(?:[a-zA-Z@]+|[\\\\{}$&#_%^~])/';

    /** Months-to-text units for a date range derived from `experience[].duration`. */
    private const UNIT_YEARS = 'yr';

    private const UNIT_MONTHS = 'mo';

    public function __construct(private readonly ModelRouterService $router) {}

    /**
     * Tailor `$profile` to `$listing` and return content ready for
     * `renderResume()`.
     *
     * @throws ResumeTailoringException when the listing/profile cannot be
     *                                  tailored at all, or when every allowed
     *                                  attempt fails
     */
    public function tailor(JobListing $listing, UserProfile $profile): TailoredResumeContent
    {
        return $this->tailorWithCorrection($listing, $profile, null);
    }

    /**
     * Tailor as {@see tailor()} does, but open the first model call with
     * `$correction` appended to the system message.
     *
     * The seam {@see ResumeTailoringPipeline} needs, and the reason it is a
     * separate method rather than an extra parameter on {@see tailor()}: the
     * ordinary caller has no correction to give and should not have to say so,
     * and `tailor()`'s two-argument signature is what the rest of the pipeline
     * and its tests are written against.
     *
     * The string is deliberately opaque here. This class knows nothing about
     * LaTeX engines or compile errors, and composing a corrective instruction
     * out of one is the pipeline's job; all this method does is give that
     * instruction the same standing as the schema-correction note produced by
     * {@see correctionNote()}. Note that the two compose rather than compete: a
     * compile-driven correction seeds attempt 1, and if *that* response comes
     * back unusable the ordinary `latex.tailoring.max_attempts` retry replaces
     * it with the stricter-format note. Losing the compile correction at that
     * point is intended — a response that is not even valid JSON has bigger
     * problems than the compile error that preceded it.
     *
     * @param  ?string  $correction  a plain-text instruction about what went wrong
     *                               with the document the previous response produced
     *
     * @throws ResumeTailoringException
     */
    public function tailorWithCorrection(
        JobListing $listing,
        UserProfile $profile,
        ?string $correction,
    ): TailoredResumeContent {
        // Both fatal checks first, before a single token is spent: neither a
        // posting with nothing to tailor against nor a profile with nothing to
        // tailor is fixable by re-prompting.
        $description = $this->descriptionFor($listing);
        $facts = $this->factualSkeleton($profile);

        $tailored = $this->runPrompt(
            $listing,
            $description,
            $facts,
            // Salary decides the tier when the listing states one (Req 4.3);
            // otherwise the complexity heuristic does (Req 4.4), fed the size of
            // the posting and the number of roles that need bullets written —
            // which is what actually makes one tailoring run harder than another.
            ModelTierContext::fromSignals(
                salaryMin: $listing->salary_min,
                salaryMax: $listing->salary_max,
                currency: $listing->currency,
                jdLength: mb_strlen($description),
                requirementCount: count($facts['roles']),
            ),
            $correction,
        );

        Log::info('Resume tailoring completed.', [
            'job_listing_id' => $listing->getKey(),
            'user_id' => $profile->user_id,
        ] + $tailored->context());

        return $tailored;
    }

    /**
     * The template key to render with, when a caller does not pick one
     * (Requirement 6.3).
     */
    public function defaultTemplateKey(): string
    {
        $key = config('latex.tailoring.default_template_key', 'default');

        return is_string($key) && trim($key) !== '' ? trim($key) : 'default';
    }

    /* ---------------------------------------------------------------------
     | The prompt, and its one stricter retry
     | -------------------------------------------------------------------- */

    /**
     * @param  array<string, mixed>  $facts
     * @param  ?string  $correction  an instruction to open the *first* attempt with, from
     *                               {@see tailorWithCorrection()}; replaced by
     *                               {@see correctionNote()} if that attempt's response is
     *                               itself unusable
     *
     * @throws ResumeTailoringException
     */
    protected function runPrompt(
        JobListing $listing,
        string $description,
        array $facts,
        ModelTierContext $context,
        ?string $correction = null,
    ): TailoredResumeContent {
        $maxAttempts = max(1, (int) config('latex.tailoring.max_attempts', 2));
        $schema = $this->schema();

        /** @var array<int, array<string, mixed>> $attempts */
        $attempts = [];
        $lastFailure = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $result = $this->router->complete(
                    self::TASK_RESUME_TAILOR,
                    $this->messages($listing, $description, $facts, $correction),
                    $context,
                    $schema,
                    // Attributes token spend to the listing being tailored for
                    // (Req 4.6).
                    $listing,
                );

                return $this->merge($result->parsedJson, $facts, $listing, $result, $attempt);
            } catch (ModelRouterException|MalformedTailoringPayload $e) {
                $lastFailure = $e;
                $parseFailure = $e instanceof MalformedTailoringPayload
                    || $this->isRouterParseFailure($e);

                $attempts[] = $record = [
                    'attempt' => $attempt,
                    'task_type' => self::TASK_RESUME_TAILOR,
                    'job_listing_id' => $listing->getKey(),
                    'parse_failure' => $parseFailure,
                    'reason' => $e->getMessage(),
                ];

                Log::warning('Resume tailoring attempt failed.', $record);

                if ($attempt >= $maxAttempts) {
                    break;
                }

                $correction = $this->correctionNote($schema, $e->getMessage());
            }
        }

        $exception = ResumeTailoringException::promptFailed(self::TASK_RESUME_TAILOR, $attempts, $lastFailure);

        Log::error($exception->getMessage(), $exception->context);

        throw $exception;
    }

    /**
     * Did a router failure come from unusable *content* rather than a failed
     * call?
     *
     * Structural rather than message-matching, and identical to the check in
     * {@see \App\Services\Scoring\JobScoringService::isRouterParseFailure()}:
     * `unparseableJson()` is the only ModelRouterException factory that records
     * the offending completion under `content`, and the exhausted-candidates
     * wrapper keeps the underlying failure as `previous`. Used only to label the
     * attempt history — the retry happens either way, since a stricter prompt
     * costs one call and a transient provider error may well have cleared.
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
     * The stricter formatting instruction the retry carries.
     *
     * Names the failure and restates the schema inline, for the same two
     * reasons as in scoring: a model that returned prose needs telling its
     * output was unusable, and a model whose provider silently dropped
     * `response_format` never saw the schema at all. The no-LaTeX rule is
     * repeated here because "your response could not be used" is not a hint a
     * model reliably connects to a `\textbf{}` it emitted.
     *
     * @param  array<string, mixed>  $schema
     */
    protected function correctionNote(array $schema, string $failure): string
    {
        return 'Your previous response could not be used: '.Str::limit($failure, 300)."\n\n"
            .'Return ONLY valid JSON matching this schema. No prose, no explanation, '
            ."no markdown code fence, no trailing commentary. Every property is required "
            ."and must use the exact name and type shown:\n"
            .$this->encode($schema)."\n\n"
            .'Every string must be plain text. Do not use LaTeX, Markdown, HTML or any '
            .'backslash command anywhere in your response — formatting is applied by the '
            .'document template, not by you.';
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array<int, array<string, string>>
     */
    protected function messages(
        JobListing $listing,
        string $description,
        array $facts,
        ?string $correction,
    ): array {
        [$maxBullets, $maxBulletChars] = [$this->maxBulletsPerRole(), $this->limit('max_bullet_chars', 300)];

        $system = 'You tailor an existing resume to one job posting. You are given the candidate\'s real '
            .'employment history and you must work strictly within it: never introduce an employer, job '
            .'title, date, qualification, technology or metric that does not already appear in the '
            .'candidate data. Tailoring means choosing what to foreground and how to word it, never '
            .'adding to the record. You do not produce a document: employers, titles, dates, education '
            .'and contact details are inserted from the candidate\'s stored profile, not from your '
            .'response, so do not repeat them. Every string you return is plain text — no LaTeX, no '
            .'Markdown, no HTML, no backslash commands. Respond with JSON matching the requested schema '
            .'and nothing else.';

        $user = "Tailor this candidate's resume content for the role below.\n\n"
            ."Return:\n"
            ."- headline: the target job title to show under the candidate's name, taken from or aligned "
            ."with the posting's title.\n"
            ."- summary: {$this->limit('max_summary_chars', 700)} characters or fewer of professional "
            ."summary, written in the third person without pronouns, foregrounding the experience this "
            ."posting cares about.\n"
            .'- emphasizedSkills: the candidate\'s existing skills that matter most for this posting, '
            ."most relevant first. Only skills listed in the candidate data — anything else is discarded.\n"
            ."- experience: one entry per role you are rewording, identified by the role's `index` from "
            ."the candidate data, with up to {$maxBullets} `bullets` of at most {$maxBulletChars} "
            ."characters each. Each bullet must restate an achievement that role already lists, reworded "
            ."to speak to this posting. Strongest bullet first. Omit a role entirely rather than "
            ."inventing bullets for it.\n\n"
            ."Do not return company names, job titles, dates, education or contact details.\n\n"
            ."=== JOB POSTING ===\n"
            ."Title: {$listing->title}\n"
            ."Company: {$listing->company}\n\n"
            ."{$description}\n\n"
            ."=== CANDIDATE DATA ===\n"
            .$this->candidateData($facts);

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

    /**
     * The schema the model is held to.
     *
     * Note what is absent: there is no `company`, `position`, `dates`,
     * `education` or `contact` property anywhere in it. The model is not asked
     * to be careful with those fields — it is given no field to put them in.
     *
     * @return array<string, mixed>
     */
    protected function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['headline', 'summary', 'emphasizedSkills', 'experience'],
            'properties' => [
                'headline' => ['type' => 'string'],
                'summary' => ['type' => 'string'],
                'emphasizedSkills' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'experience' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['index', 'bullets'],
                        'properties' => [
                            // The binding that keeps employers out of the model's
                            // output: a role is referenced by position in the
                            // candidate data, never by name.
                            'index' => [
                                'type' => 'integer',
                                'minimum' => 0,
                                'description' => 'The `index` of the role in the candidate data.',
                            ],
                            'bullets' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /* ---------------------------------------------------------------------
     | The factual skeleton: everything that must not come from a model
     | -------------------------------------------------------------------- */

    /**
     * Project the profile into the template's shape, minus the parts the model
     * supplies.
     *
     * Built once and used twice — as the candidate data in the prompt and as the
     * base the response is merged over — so the `index` the model answers with
     * cannot mean a different role than the one it was shown.
     *
     * @return array{name: string, contact: array<int, string>, roles: array<int, array<string, mixed>>,
     *               education: array<int, array<string, string>>, skillGroups: array<int, array<string, mixed>>}
     *
     * @throws ResumeTailoringException when there is nothing to tailor
     */
    protected function factualSkeleton(UserProfile $profile): array
    {
        $skeleton = [
            'name' => $this->candidateName($profile),
            'contact' => $this->contactParts($profile),
            'roles' => $this->factualRoles($profile),
            'education' => $this->factualEducation($profile),
            'skillGroups' => $this->profileSkillGroups($profile),
        ];

        // A profile with no history, no schooling and no skills yields a page
        // with a name on it. That is a pipeline-ordering problem — the resume was
        // never parsed, or parsing failed — and no prompt fixes it.
        if ($skeleton['roles'] === [] && $skeleton['education'] === [] && $skeleton['skillGroups'] === []) {
            throw ResumeTailoringException::nothingToTailor(
                'the profile has no experience, education or skills; the resume needs parsing first'
            );
        }

        return $skeleton;
    }

    /**
     * The most recent roles, with their factual fields and their source
     * achievements.
     *
     * A role with neither employer nor title is dropped: the template drops it
     * too, and offering it to the model would invite bullets that can never be
     * rendered.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function factualRoles(UserProfile $profile): array
    {
        $roles = [];

        foreach ($this->arrayOf($profile->experience) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $company = $this->text($entry['company'] ?? null);
            $position = $this->text($entry['position'] ?? null);

            if ($company === '' && $position === '') {
                continue;
            }

            $roles[] = [
                'company' => $company,
                'position' => $position,
                // `user_profiles.experience` carries no per-role location, so the
                // template's optional field stays empty rather than being guessed.
                'location' => '',
                'dates' => $this->formatDuration($entry['duration'] ?? null),
                'achievements' => $this->stringList($entry['achievements'] ?? null),
            ];
        }

        return array_slice($roles, 0, max(1, $this->limit('max_experience_entries', 6)));
    }

    /**
     * @return array<int, array<string, string>>
     */
    protected function factualEducation(UserProfile $profile): array
    {
        $schools = [];

        foreach ($this->arrayOf($profile->education) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $institution = $this->text($entry['institution'] ?? null);
            $degree = $this->text($entry['degree'] ?? null);

            if ($institution === '' && $degree === '') {
                continue;
            }

            $schools[] = [
                'institution' => $institution,
                'degree' => $degree,
                // The profile spells this `fieldOfStudy`; the template calls it
                // `field`. Translated here, which is the only place that knows both.
                'field' => $this->text($entry['fieldOfStudy'] ?? $entry['field'] ?? null),
                'location' => '',
                'dates' => $this->formatYearRange($entry['startYear'] ?? null, $entry['endYear'] ?? null),
            ];
        }

        return $schools;
    }

    /**
     * The profile's skills, as the template's labelled groups.
     *
     * Handles both shapes the profile can hold: the parser's
     * `{primary: [...], secondary: [...]}` and a hand-edited flat list (see
     * `Api\ProfileController`). A flat list gets no label, so it renders as one
     * plain row instead of a row headed "Skills:" under a "Skills" section.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function profileSkillGroups(UserProfile $profile): array
    {
        $labels = config('latex.tailoring.skill_group_labels', []);
        $labels = is_array($labels) ? $labels : [];
        $default = (string) config('latex.tailoring.default_skill_group_label', 'Skills');

        $groups = [];
        $ungrouped = [];

        foreach ($this->arrayOf($profile->skills) as $key => $value) {
            if (is_array($value)) {
                $items = $this->stringList($value);

                if ($items === []) {
                    continue;
                }

                $groups[] = [
                    'label' => is_string($key) ? ($labels[$key] ?? $default) : null,
                    'items' => $items,
                ];

                continue;
            }

            $text = $this->text($value);

            if ($text !== '') {
                $ungrouped[] = $text;
            }
        }

        if ($ungrouped !== []) {
            $groups[] = ['label' => null, 'items' => array_values(array_unique($ungrouped))];
        }

        return $groups;
    }

    protected function candidateName(UserProfile $profile): string
    {
        // The relation may be unloaded (or absent, for an unsaved profile), and a
        // missing name is not worth failing a render over — the template treats it
        // as optional.
        return $this->text($profile->user?->name ?? null);
    }

    /**
     * Email, location and links, in the order they read best in a header.
     *
     * @return array<int, string>
     */
    protected function contactParts(UserProfile $profile): array
    {
        $parts = [
            $this->text($profile->user?->email ?? null),
            $this->locationText($profile),
            $this->text($profile->linkedin_url),
            $this->text($profile->github_url),
            $this->text($profile->portfolio_url),
        ];

        return array_values(array_filter($parts, fn (string $part): bool => $part !== ''));
    }

    /** `user_profiles.location` is `{city, state, country}`; any part may be absent. */
    protected function locationText(UserProfile $profile): string
    {
        $location = $profile->location;

        if (is_string($location)) {
            return $this->text($location);
        }

        $parts = [];

        foreach (['city', 'state', 'country'] as $key) {
            $value = $this->text($this->arrayOf($location)[$key] ?? null);

            if ($value !== '') {
                $parts[] = $value;
            }
        }

        return implode(', ', $parts);
    }

    /**
     * A date range for a role, from whatever the profile holds.
     *
     * `experience[].duration` is a month count when the parser wrote it and free
     * text when a user hand-edited it (both shapes are accepted by
     * `Api\ProfileController`). Free text passes through verbatim — it is the
     * user's own factual statement, and reinterpreting it would be exactly the
     * kind of quiet alteration the fabrication guard exists to catch.
     */
    protected function formatDuration(mixed $duration): string
    {
        if (is_string($duration)) {
            return $this->text($duration);
        }

        if (! is_int($duration) && ! is_float($duration)) {
            return '';
        }

        $months = (int) round((float) $duration);

        if ($months <= 0) {
            return '';
        }

        $years = intdiv($months, 12);
        $remainder = $months % 12;

        $parts = [];

        if ($years > 0) {
            $parts[] = $years.' '.self::UNIT_YEARS;
        }

        if ($remainder > 0) {
            $parts[] = $remainder.' '.self::UNIT_MONTHS;
        }

        return implode(' ', $parts);
    }

    protected function formatYearRange(mixed $start, mixed $end): string
    {
        $start = $this->text(is_scalar($start) ? (string) $start : null);
        $end = $this->text(is_scalar($end) ? (string) $end : null);

        return match (true) {
            $start !== '' && $end !== '' && $start !== $end => $start.' - '.$end,
            $start !== '' => $start,
            default => $end,
        };
    }

    /**
     * The candidate data the prompt sees: the factual skeleton, indexed.
     *
     * Contact details are excluded — a header the model cannot influence is a
     * header it does not need to see, and an email address in a prompt is one
     * more copy of it in a provider's logs.
     *
     * @param  array<string, mixed>  $facts
     */
    protected function candidateData(array $facts): string
    {
        $roles = [];

        foreach ($facts['roles'] as $index => $role) {
            $roles[] = [
                'index' => $index,
                'company' => $role['company'],
                'position' => $role['position'],
                'dates' => $role['dates'],
                'achievements' => $role['achievements'],
            ];
        }

        $skills = [];

        foreach ($facts['skillGroups'] as $group) {
            foreach ($group['items'] as $item) {
                $skills[] = $item;
            }
        }

        return $this->truncate($this->encode([
            'skills' => array_values(array_unique($skills)),
            'experience' => $roles,
            'education' => $facts['education'],
        ]), 'max_profile_chars', 12000);
    }

    /**
     * The description text tailored against.
     *
     * An empty description is a failure rather than a prompt: tailoring to
     * nothing would produce a confidently generic resume that looks tailored.
     * Enrichment (Requirement 3.4) is what fills this in, so a listing reaching
     * here empty is a pipeline-ordering bug.
     *
     * @throws ResumeTailoringException
     */
    protected function descriptionFor(JobListing $listing): string
    {
        $description = trim((string) $listing->description);

        if ($description === '') {
            throw ResumeTailoringException::nothingToTailor(
                'the job listing has no description to tailor against; it needs enrichment first'
            );
        }

        return $this->truncate($description, 'max_description_chars', 12000);
    }

    /* ---------------------------------------------------------------------
     | The merge: model wording over factual skeleton
     | -------------------------------------------------------------------- */

    /**
     * Fold the model's response into the factual skeleton and produce the
     * template's content array.
     *
     * Reads as a merge in one direction only: the skeleton is authoritative and
     * the response can fill three holes in it. Anything the response says that
     * does not fit one of those holes is dropped here, silently as far as the
     * document is concerned and visibly in the log.
     *
     * @param  array<mixed>  $parsed
     * @param  array<string, mixed>  $facts
     *
     * @throws MalformedTailoringPayload when the response is decodable but unusable
     */
    protected function merge(
        array $parsed,
        array $facts,
        JobListing $listing,
        ModelCompletionResult $result,
        int $attempt,
    ): TailoredResumeContent {
        $summary = $this->plainText(
            $this->requiredString($parsed, 'summary'),
            'summary',
            $this->limit('max_summary_chars', 700)
        );

        // The posting's own title is a better default than nothing, and it is a
        // fact about the *listing* rather than about the candidate, so using it
        // fabricates no history.
        $headline = $this->optionalString($parsed, 'headline') ?? $this->text($listing->title);
        $headline = $headline === ''
            ? ''
            : $this->plainText($headline, 'headline', $this->limit('max_headline_chars', 120));

        $bulletsByIndex = $this->bulletsByRoleIndex($parsed, count($facts['roles']));

        // Every role was skipped, or every bullet referenced a role that does not
        // exist. Not a merge this class can quietly paper over: the profile's raw
        // achievements would render as an *untailored* resume that the rest of the
        // pipeline would treat as tailored.
        if ($facts['roles'] !== [] && $bulletsByIndex === []) {
            throw new MalformedTailoringPayload(
                'Tailoring output contains no usable bullets for any of the '
                .count($facts['roles']).' role(s) in the profile.'
            );
        }

        [$experience, $roleFacts] = $this->mergeExperience($facts['roles'], $bulletsByIndex);
        [$skills, $dropped] = $this->emphasizeSkills($facts['skillGroups'], $this->stringList($parsed['emphasizedSkills'] ?? null));

        if ($dropped !== []) {
            // Worth a line of its own: a model repeatedly naming skills the
            // profile lacks is either being led by the posting or reading a stale
            // profile, and both are things an operator wants to notice.
            Log::info('Discarded emphasized skills absent from the profile.', [
                'job_listing_id' => $listing->getKey(),
                'skills' => $dropped,
            ]);
        }

        return new TailoredResumeContent(
            content: [
                'name' => $facts['name'],
                'headline' => $headline,
                'contact' => $facts['contact'],
                'summary' => $summary,
                'skills' => $skills,
                'experience' => $experience,
                'education' => $facts['education'],
            ],
            facts: $roleFacts,
            droppedSkills: $dropped,
            rawOutput: $result->content,
            modelUsed: $result->modelUsed,
            tierUsed: $result->tierUsed,
            attempts: $attempt,
        );
    }

    /**
     * Bullets keyed by the role index they belong to, validated and bounded.
     *
     * An index outside the projection is dropped rather than failed on: a model
     * that answers for six roles when it was shown four has still done useful
     * work on the four. A duplicate index keeps the first entry, since merging
     * two competing bullet sets would produce a role with twice the bullets it
     * is allowed.
     *
     * @param  array<mixed>  $parsed
     * @return array<int, array<int, string>>
     */
    protected function bulletsByRoleIndex(array $parsed, int $roleCount): array
    {
        $entries = $parsed['experience'] ?? null;

        if (! is_array($entries)) {
            return [];
        }

        $maxBullets = $this->maxBulletsPerRole();
        $maxChars = $this->limit('max_bullet_chars', 300);
        $byIndex = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $index = $entry['index'] ?? null;

            // JSON has one number type, so a whole-number float is the same answer
            // as the integer; 1.5 is not an index at all.
            if (is_float($index) && $index === floor($index)) {
                $index = (int) $index;
            }

            if (! is_int($index) || $index < 0 || $index >= $roleCount || array_key_exists($index, $byIndex)) {
                continue;
            }

            $bullets = [];

            foreach ($this->stringList($entry['bullets'] ?? null) as $bullet) {
                $bullets[] = $this->plainText($bullet, "experience[{$index}].bullets", $maxChars);

                if (count($bullets) >= $maxBullets) {
                    break;
                }
            }

            if ($bullets !== []) {
                $byIndex[$index] = $bullets;
            }
        }

        return $byIndex;
    }

    /**
     * Factual roles with tailored bullets attached.
     *
     * A role the model skipped keeps its own stored achievements rather than
     * appearing bullet-less: those are the candidate's own words about a job
     * they held, so they are the safest possible fallback — untailored, but
     * true. A role with neither is dropped, because the template would render a
     * heading with nothing under it.
     *
     * @param  array<int, array<string, mixed>>  $roles
     * @param  array<int, array<int, string>>  $bulletsByIndex
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, string>>}
     */
    protected function mergeExperience(array $roles, array $bulletsByIndex): array
    {
        $maxBullets = $this->maxBulletsPerRole();
        $experience = [];
        $facts = [];

        foreach ($roles as $index => $role) {
            $bullets = $bulletsByIndex[$index]
                ?? array_slice($role['achievements'], 0, $maxBullets);

            if ($bullets === []) {
                continue;
            }

            $experience[] = [
                // Straight from the profile. The model has no field for any of
                // these, so there is nothing to overwrite them with.
                'position' => $role['position'],
                'company' => $role['company'],
                'location' => $role['location'],
                'dates' => $role['dates'],
                'bullets' => array_values($bullets),
            ];

            // What task 11.5 diffs the rendered document against. Same order as
            // the entries above, so a finding points at a specific role.
            $facts[] = [
                'company' => $role['company'],
                'position' => $role['position'],
                'dates' => $role['dates'],
            ];
        }

        return [$experience, $facts];
    }

    /**
     * Reorder the profile's skill groups so the model's emphasized skills come
     * first, and report the ones it made up.
     *
     * Emphasis is an *ordering* over an existing set, never a membership
     * change. A skill the profile does not claim is returned as dropped, which
     * is the whole reason the model is allowed near this field at all: the
     * ordering is genuinely useful (a reader scans the first few items) and it
     * is the one contribution that cannot fabricate anything.
     *
     * @param  array<int, array<string, mixed>>  $groups
     * @param  array<int, string>  $emphasized
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, string>}
     */
    protected function emphasizeSkills(array $groups, array $emphasized): array
    {
        $emphasized = array_slice($emphasized, 0, max(0, $this->limit('max_emphasized_skills', 15)));
        $maxPerGroup = max(1, $this->limit('max_skills_per_group', 18));

        // Compared case- and whitespace-insensitively: "postgresql" and
        // "PostgreSQL" are the same skill, and the document must show the
        // profile's spelling, not the model's.
        $rank = [];
        $matched = [];

        foreach ($emphasized as $position => $skill) {
            $rank[$this->normalize($skill)] ??= $position;
        }

        $ordered = [];

        foreach ($groups as $group) {
            $items = $group['items'];

            $keyed = [];

            foreach ($items as $position => $item) {
                $key = $this->normalize($item);

                if (array_key_exists($key, $rank)) {
                    $matched[$key] = true;
                }

                $keyed[] = [
                    // Emphasized items sort ahead of everything else, in the order
                    // the model gave them; the rest keep the profile's own order.
                    'emphasis' => $rank[$key] ?? PHP_INT_MAX,
                    'position' => $position,
                    'item' => $item,
                ];
            }

            usort($keyed, fn (array $a, array $b): int => [$a['emphasis'], $a['position']] <=> [$b['emphasis'], $b['position']]);

            $ordered[] = [
                'label' => $group['label'],
                'items' => array_slice(array_column($keyed, 'item'), 0, $maxPerGroup),
            ];
        }

        $dropped = [];

        foreach ($emphasized as $skill) {
            if (! array_key_exists($this->normalize($skill), $matched)) {
                $dropped[] = $skill;
            }
        }

        return [$ordered, array_values(array_unique($dropped))];
    }

    /* ---------------------------------------------------------------------
     | Payload validation helpers
     | -------------------------------------------------------------------- */

    /**
     * A model-written string, confirmed to be prose and bounded in length.
     *
     * The LaTeX check is a *rejection*, not a sanitisation, and it is worth
     * being explicit about why given the template already escapes everything.
     * Escaping makes a smuggled `\input{}` harmless — it would typeset as the
     * literal text — which means the document would silently ship with
     * `\textbf{Led} a team` printed on it. So the escaper protects the
     * compiler and this check protects the *content*: a response containing
     * backslash commands is a response written to a contract other than the one
     * it was given, and the right move is one stricter retry rather than
     * printing it.
     *
     * @throws MalformedTailoringPayload
     */
    protected function plainText(string $value, string $field, int $maxChars): string
    {
        if (preg_match(self::LATEX_COMMAND_PATTERN, $value) === 1) {
            throw new MalformedTailoringPayload(sprintf(
                'Tailoring output field `%s` contains LaTeX markup, which is not allowed: "%s". '
                .'The document template applies all formatting.',
                $field,
                Str::limit($value, 120)
            ));
        }

        if ($maxChars <= 0 || mb_strlen($value) <= $maxChars) {
            return $value;
        }

        // The budget is a hard ceiling on the *result*, ellipsis included — a cap
        // that its own truncation marker pushes one character over is not a cap.
        return Str::limit($value, $maxChars - 1, '…');
    }

    /**
     * @param  array<mixed>  $parsed
     *
     * @throws MalformedTailoringPayload when the field is missing or blank
     */
    protected function requiredString(array $parsed, string $key): string
    {
        $value = $parsed[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new MalformedTailoringPayload(
                "Tailoring output is missing a usable `{$key}` string (got ".$this->describe($value).').'
            );
        }

        return trim($value);
    }

    /**
     * @param  array<mixed>  $parsed
     */
    protected function optionalString(array $parsed, string $key): ?string
    {
        $value = $parsed[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * A list of non-empty trimmed strings from anything.
     *
     * Non-string entries are dropped rather than stringified: a nested object
     * where a bullet was expected means the model answered a different question,
     * and the surviving strings are still usable.
     *
     * @return array<int, string>
     */
    protected function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $entry) {
            $text = is_string($entry) ? trim($entry) : '';

            if ($text !== '') {
                $strings[] = $text;
            }
        }

        return array_values(array_unique($strings));
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
     | Small shared utilities
     | -------------------------------------------------------------------- */

    /** @return array<mixed> */
    protected function arrayOf(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    protected function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    protected function normalize(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    protected function maxBulletsPerRole(): int
    {
        return max(1, $this->limit('max_bullets_per_role', 4));
    }

    protected function limit(string $key, int $default): int
    {
        return (int) config("latex.tailoring.{$key}", $default);
    }

    protected function truncate(string $value, string $configKey, int $default): string
    {
        $limit = $this->limit($configKey, $default);

        return $limit > 0 ? Str::limit($value, $limit, ' …[truncated]') : $value;
    }

    /** @param  array<mixed>  $value */
    protected function encode(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}

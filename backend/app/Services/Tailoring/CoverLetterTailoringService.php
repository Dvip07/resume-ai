<?php

namespace App\Services\Tailoring;

use App\Exceptions\CoverLetterTailoringException;
use App\Exceptions\ModelRouterException;
use App\Models\JobListing;
use App\Models\UserProfile;
use App\Services\ModelCompletionResult;
use App\Services\ModelRouterService;
use App\Services\ModelTierContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The LLM half of cover-letter tailoring (Requirement 7.1): given a posting and
 * a profile, produce the content array
 * {@see \App\Services\Latex\LatexRenderService::renderCoverLetter()} consumes.
 *
 * The sibling of {@see ResumeTailoringService}, and written to read as one: the
 * same prompt-plus-schema-plus-bounded-retry shape, the same factual projection
 * taken from {@see UserProfile} before any model call, the same rejection of
 * LaTeX in prose, the same config-driven budgets. Where it differs from that
 * class, it differs on purpose and the difference is noted below.
 *
 * ## The model never restates a fact
 *
 * The same structural argument as the resume service's, which states it at
 * length under the same heading — read that first; this is the cover-letter
 * application of it. The model is given exactly two fields:
 *
 *  - `paragraphs`: the body of the letter, in the order it should be read,
 *  - `closing`: optionally, the sign-off line.
 *
 * Everything else in the document comes from somewhere that cannot be wrong.
 * The candidate's name and contact details are projected from the profile; the
 * company and the job title from the {@see JobListing}; the date from the clock;
 * the recipient from a caller who has a real source for one, or from nowhere.
 * The schema below has no property for any of them, so — as in the resume
 * service — a model cannot get them wrong, because it is never asked for them.
 *
 * There is one thing worth saying that the resume service does not have to. In a
 * resume, a fabricated employer sits in a field, and a field can be diffed. In a
 * letter, everything the model writes is running prose, so a wrong company name
 * would arrive *inside a sentence* — where no field-level check finds it. That
 * is why the template carries a subject line ("Re: <title> at <company>") built
 * from the listing rather than leaving the opening paragraph to name the job:
 * the two facts a reader checks first are present in the document whether the
 * model's prose mentions them or not, and the prompt can then tell the model not
 * to bother repeating them. It is also why {@see TailoredCoverLetterContent}
 * carries {@see TailoredCoverLetterContent::$facts} for the fabrication guard of
 * task 11.5, which reads the *rendered* letter rather than trusting this
 * assembly.
 *
 * ## The recipient is never the model's business
 *
 * `recipient_name`, `recipient_title` and `company_address` are usually unknown,
 * and this class will not let a model fill them in. A fabricated hiring
 * manager's name is the single worst output the feature can produce: "Dear Sarah
 * Mitchell," addressed to a company that employs no Sarah Mitchell is a false
 * statement in the user's own voice, and it discredits the application in a way
 * no clumsy paragraph does. So the fields have no schema property, no mention in
 * the prompt, and exactly one route in — a {@see CoverLetterRecipient} passed by
 * a caller that got them from a real source (a posting that names a contact, a
 * user who typed one in).
 *
 * Absent that, the letter is addressed "Dear Hiring Manager," by the template's
 * own fallback, and the recipient block simply starts at the company. That
 * fallback lives in the template rather than here, which is a deliberate
 * division the template's header comment argues for: it is a *layout*
 * degradation rule, like dropping an empty section, and putting it there means
 * no future second caller can get the no-recipient case wrong. This class's part
 * of the bargain is to pass `null` rather than a guess, and never to let the
 * model near the question.
 *
 * ## The date
 *
 * `date` is formatted here from `now()` through
 * `config('cover_letter.generation.date_format')`, and that choice is worth
 * justifying against the two alternatives.
 *
 * A `date` *argument* was rejected because it pushes locale and timezone
 * formatting onto every caller, and the template explicitly refuses to do any
 * date formatting ("locale and timezone are the caller's business") — so a
 * pre-formatted string argument would make each caller re-decide the house
 * format, and a `CarbonInterface` argument would leave the formatting here
 * anyway with an extra parameter to thread through the queued job. An injected
 * clock was rejected because Laravel already has one: `now()` resolves through
 * the container's `Illuminate\Support\Facades\Date` clock, which
 * `Carbon::setTestNow()` and `$this->travelTo()` swap wholesale. A
 * constructor-injected `ClockInterface` would add a dependency to re-solve a
 * problem the framework has already solved, and the tests below fix the date by
 * travelling in time — which is both deterministic and the idiomatic seam.
 *
 * The date is resolved once per {@see tailor()} call and carried in the returned
 * content array, so a caller that re-renders the same result — the
 * compile-retry loop, a regenerated PDF — gets the same date it started with,
 * rather than a letter that changes its date if a retry crosses midnight.
 *
 * ## Retry contract
 *
 * Identical to the resume service's, down to the config key layout:
 * `config('cover_letter.generation.max_attempts')` attempts — one normal, then
 * one carrying a stricter "return ONLY valid JSON matching this schema"
 * instruction plus the schema and a note on what went wrong. Both a
 * router-level parse failure and a semantically unusable payload (no
 * paragraphs, one paragraph where a letter needs an argument, LaTeX in the
 * prose) trigger it. When the retry also fails this throws
 * {@see CoverLetterTailoringException} — a *separate* type from
 * {@see \App\Exceptions\ResumeTailoringException}, because a caller's correct
 * response differs: a failed resume is fatal to the application and a failed
 * cover letter usually is not. That exception's docblock makes the full
 * argument.
 *
 * As in scoring and resume tailoring, this retry sits *above* the router's own
 * same-tier model fallback (Requirement 4.5): by the time a failure surfaces
 * here every model in the tier has been asked with the original wording, so
 * re-asking is only worth it because the wording changes.
 *
 * ## Scope
 *
 * Out of scope here, on purpose: deciding whether a letter is wanted at all —
 * that is {@see CoverLetterRequirementDetector} (Requirement 7.2, task 12.2),
 * and this class assumes the answer was yes; rendering and compiling
 * ({@see \App\Services\Latex\LatexRenderService}); and the queued
 * `TailorCoverLetter` job that ties the three together and persists the result.
 * This class returns a value object or throws; that is its whole contract, which
 * is what lets it be tested against a faked router with no database and no TeX
 * engine.
 */
class CoverLetterTailoringService
{
    /** Task type recorded in `model_usage_logs` (design.md §1, Requirement 4.6). */
    public const TASK_COVER_LETTER_TAILOR = 'cover_letter_tailor';

    /**
     * A TeX control sequence: a backslash followed by a command name or by any
     * of the characters LaTeX lets you escape.
     *
     * Character-for-character the resume service's
     * `ResumeTailoringService::LATEX_COMMAND_PATTERN`, and for the reason argued
     * there: the backslash is the whole discriminator. `%`, `&`, `$`, `#` and
     * `_` are ordinary characters in prose a cover letter genuinely contains
     * ("cut costs 30%", "R&D", "$2M in ARR"), the template escapes each of them
     * by construction, and treating them as suspicious would reject good writing
     * for nothing. A backslash has no business in a sentence about wanting a job
     * — it is either `\textbf{}` (a model ignoring the contract) or
     * `\input{/etc/passwd}` (a model being steered by a hostile job description,
     * which is untrusted text this prompt necessarily includes).
     *
     * Duplicated rather than shared: it is one line, and a base class holding
     * one constant would couple two prompts that otherwise have nothing in
     * common. If a third document kind arrives, extract it then.
     */
    private const LATEX_COMMAND_PATTERN = '/\\\\(?:[a-zA-Z@]+|[\\\\{}$&#_%^~])/';

    /** Markdown emphasis, headings and fenced code, as a model habitually emits them. */
    private const MARKDOWN_PATTERN = '/(?:\*\*|__|```|^\s*#{1,6}\s|^\s*[-*]\s)/m';

    public function __construct(private readonly ModelRouterService $router) {}

    /**
     * Write a cover letter for `$listing` from `$profile` and return content
     * ready for `renderCoverLetter()`.
     *
     * @param  ?CoverLetterRecipient  $recipient  a hiring contact the caller actually
     *                                            knows; omit it, and the template's
     *                                            "Dear Hiring Manager," fallback applies.
     *                                            Never derived from a model — see the
     *                                            class docblock.
     *
     * @throws CoverLetterTailoringException when there is nothing to write from, or
     *                                       when every allowed attempt fails
     */
    public function tailor(
        JobListing $listing,
        UserProfile $profile,
        ?CoverLetterRecipient $recipient = null,
    ): TailoredCoverLetterContent {
        // Both fatal checks first, before a single token is spent: neither a
        // posting with nothing to write about nor a profile with nothing to say
        // is fixable by re-prompting.
        $description = $this->descriptionFor($listing);
        $facts = $this->factualSkeleton($listing, $profile, $recipient);

        $letter = $this->runPrompt(
            $listing,
            $description,
            $facts,
            // Salary decides the tier when the listing states one (Req 4.3);
            // otherwise the complexity heuristic does (Req 4.4), fed the size of
            // the posting and the number of roles the letter may draw on. Not
            // pinned to a tier the way detection is: writing a page of persuasive
            // prose is exactly the kind of work Requirement 4.3 wants escalated
            // when the role is worth escalating for.
            ModelTierContext::fromSignals(
                salaryMin: $listing->salary_min,
                salaryMax: $listing->salary_max,
                currency: $listing->currency,
                jdLength: mb_strlen($description),
                requirementCount: count($facts['roles']),
            ),
        );

        Log::info('Cover letter tailoring completed.', [
            'job_listing_id' => $listing->getKey(),
            'user_id' => $profile->user_id,
        ] + $letter->context());

        return $letter;
    }

    /**
     * The template key to render with, when a caller does not pick one.
     *
     * Read per call rather than captured at construction, like the resume
     * service's: the value is env-driven, and a queue worker booted before a
     * config change would otherwise keep the old key for its whole life.
     */
    public function defaultTemplateKey(): string
    {
        $key = config('cover_letter.generation.default_template_key', 'default');

        return is_string($key) && trim($key) !== '' ? trim($key) : 'default';
    }

    /* ---------------------------------------------------------------------
     | The prompt, and its one stricter retry
     | -------------------------------------------------------------------- */

    /**
     * @param  array<string, mixed>  $facts
     *
     * @throws CoverLetterTailoringException
     */
    protected function runPrompt(
        JobListing $listing,
        string $description,
        array $facts,
        ModelTierContext $context,
    ): TailoredCoverLetterContent {
        $maxAttempts = max(1, (int) config('cover_letter.generation.max_attempts', 2));
        $schema = $this->schema();
        $correction = null;

        /** @var array<int, array<string, mixed>> $attempts */
        $attempts = [];
        $lastFailure = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $result = $this->router->complete(
                    self::TASK_COVER_LETTER_TAILOR,
                    $this->messages($listing, $description, $facts, $correction),
                    $context,
                    $schema,
                    // Attributes token spend to the listing being applied to
                    // (Req 4.6).
                    $listing,
                );

                return $this->assemble($result->parsedJson, $facts, $result, $attempt);
            } catch (ModelRouterException|MalformedTailoringPayload $e) {
                $lastFailure = $e;
                $parseFailure = $e instanceof MalformedTailoringPayload
                    || $this->isRouterParseFailure($e);

                $attempts[] = $record = [
                    'attempt' => $attempt,
                    'task_type' => self::TASK_COVER_LETTER_TAILOR,
                    'job_listing_id' => $listing->getKey(),
                    'parse_failure' => $parseFailure,
                    'reason' => $e->getMessage(),
                ];

                Log::warning('Cover letter tailoring attempt failed.', $record);

                if ($attempt >= $maxAttempts) {
                    break;
                }

                $correction = $this->correctionNote($schema, $e->getMessage());
            }
        }

        $exception = CoverLetterTailoringException::promptFailed(
            self::TASK_COVER_LETTER_TAILOR,
            $attempts,
            $lastFailure,
        );

        Log::error($exception->getMessage(), $exception->context);

        throw $exception;
    }

    /**
     * Did a router failure come from unusable *content* rather than a failed
     * call?
     *
     * Structural rather than message-matching, and identical to the checks in
     * {@see ResumeTailoringService::isRouterParseFailure()} and
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
     * Names the failure and restates the schema inline, for the same two reasons
     * as in resume tailoring and scoring: a model that returned prose needs
     * telling its output was unusable, and a model whose provider silently
     * dropped `response_format` never saw the schema at all. The no-markup rule
     * is repeated because "your response could not be used" is not a hint a model
     * reliably connects to the `**bold**` or the `\textbf{}` it emitted.
     *
     * @param  array<string, mixed>  $schema
     */
    protected function correctionNote(array $schema, string $failure): string
    {
        return 'Your previous response could not be used: '.Str::limit($failure, 300)."\n\n"
            .'Return ONLY valid JSON matching this schema. No prose, no explanation, '
            .'no markdown code fence, no trailing commentary. Every required property must '
            ."use the exact name and type shown:\n"
            .$this->encode($schema)."\n\n"
            .'Every string must be plain text. Do not use LaTeX, Markdown, HTML, asterisks '
            .'for emphasis or any backslash command anywhere in your response — all '
            .'formatting, the letterhead, the date, the address and the salutation are '
            .'applied by the document template, not by you.';
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
        $maxParagraphs = $this->maxParagraphs();
        $minParagraphs = $this->minParagraphs();
        $maxChars = $this->limit('max_paragraph_chars', 900);

        $system = 'You write the body of a cover letter for one job application. You are given the '
            .'candidate\'s real career history and you must work strictly within it: never introduce '
            .'an employer, job title, date, qualification, technology, metric or achievement that does '
            .'not already appear in the candidate data. Write in the first person, as the candidate, in '
            .'plain confident prose without flattery or clichés. You do not produce a document: the '
            .'letterhead, contact details, date, recipient, salutation and signature are inserted from '
            .'stored data, not from your response. You do not know who will read the letter, so never '
            .'address anyone by name and never invent a hiring manager, a department or an address. '
            .'Every string you return is plain text — no LaTeX, no Markdown, no HTML, no asterisks for '
            .'emphasis, no backslash commands, no salutation and no sign-off inside a paragraph. '
            .'Respond with JSON matching the requested schema and nothing else.';

        $user = "Write the body of this candidate's cover letter for the role below.\n\n"
            ."Return:\n"
            ."- paragraphs: between {$minParagraphs} and {$maxParagraphs} paragraphs, each at most "
            ."{$maxChars} characters, in the order they should be read. Open with why this specific "
            ."role and this specific organisation; use the middle paragraph(s) to connect the "
            ."candidate's actual experience to what the posting asks for, naming concrete work they "
            ."have already done; close by stating interest in discussing the role. The letter must fit "
            ."on one page, so write fewer, tighter paragraphs rather than more.\n"
            ."- closing: optionally, the sign-off line, e.g. \"Sincerely,\". Omit it to use the "
            ."default.\n\n"
            ."The letter already carries a subject line reading \"Re: {$listing->title} at "
            ."{$listing->company}\", so do not open by restating the job title and company as if "
            ."announcing them. Do not write a greeting, a date, an address, the candidate's name or "
            ."their contact details — those are inserted for you, and repeating them duplicates them "
            ."in the finished letter.\n\n"
            ."=== JOB POSTING ===\n"
            ."Title: {$listing->title}\n"
            ."Company: {$listing->company}\n\n"
            ."{$description}\n\n"
            ."=== CANDIDATE DATA ===\n"
            .$this->candidateData($facts);

        if ($correction !== null) {
            // Appended to the system message so the correction carries the same
            // weight as the original instructions rather than reading as one more
            // thing the user asked for.
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
     * Note what is absent, which is nearly everything the letter contains: there
     * is no `name`, `contact`, `date`, `recipient_name`, `recipient_title`,
     * `company`, `company_address` or `job_title` property. The model is not
     * asked to be careful with the recipient's name — it is given no field to put
     * one in.
     *
     * `closing` is the one optional property, and it is optional in the honest
     * sense: leaving it out is a valid response, and the template supplies
     * "Sincerely," when it is missing.
     *
     * @return array<string, mixed>
     */
    protected function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['paragraphs'],
            'properties' => [
                'paragraphs' => [
                    'type' => 'array',
                    'minItems' => max(1, $this->minParagraphs()),
                    'maxItems' => $this->maxParagraphs(),
                    'items' => ['type' => 'string'],
                ],
                'closing' => ['type' => 'string'],
            ],
        ];
    }

    /* ---------------------------------------------------------------------
     | The factual skeleton: everything that must not come from a model
     | -------------------------------------------------------------------- */

    /**
     * Project the listing, the profile and the caller's recipient into the parts
     * of the letter the model does not write.
     *
     * Deliberately *not* shared with
     * {@see ResumeTailoringService::factualSkeleton()}, which looks similar and
     * is not: that projection is template-shaped and indexed because the model's
     * response is merged *over* it, role by role. This one is only ever *read* —
     * once into the prompt, once into the letterhead — so it needs no index
     * binding, no education-to-template field mapping and no skill grouping. A
     * shared abstraction would have to carry all of that for the benefit of the
     * caller that does not use it.
     *
     * @return array{name: string, contact: array<int, string>, roles: array<int, array<string, mixed>>,
     *               education: array<int, array<string, string>>, skills: array<int, string>,
     *               company: string, jobTitle: string, date: string, recipientName: ?string,
     *               recipientTitle: ?string, companyAddress: array<int, string>}
     *
     * @throws CoverLetterTailoringException when the profile has nothing to write from
     */
    protected function factualSkeleton(
        JobListing $listing,
        UserProfile $profile,
        ?CoverLetterRecipient $recipient,
    ): array {
        $roles = $this->factualRoles($profile);
        $education = $this->factualEducation($profile);
        $skills = $this->profileSkills($profile);

        // A profile with no history, no schooling and no skills yields a letter
        // that can only be generic flattery — the model would have nothing true to
        // say and would say something anyway. That is a pipeline-ordering problem
        // (the resume was never parsed, or parsing failed) and no prompt fixes it.
        if ($roles === [] && $education === [] && $skills === []) {
            throw CoverLetterTailoringException::nothingToWrite(
                'the profile has no experience, education or skills; the resume needs parsing first'
            );
        }

        return [
            'name' => $this->candidateName($profile),
            'contact' => $this->contactParts($profile),
            'roles' => $roles,
            'education' => $education,
            'skills' => $skills,
            'company' => $this->text($listing->company),
            'jobTitle' => $this->text($listing->title),
            'date' => $this->letterDate(),
            // Straight through from the caller, or null. There is no third source.
            'recipientName' => $recipient?->name(),
            'recipientTitle' => $recipient?->title(),
            'companyAddress' => $recipient?->addressLines() ?? [],
        ];
    }

    /**
     * Today's date, in the configured display format.
     *
     * `now()` rather than an injected clock or a date argument — the class
     * docblock argues the choice. Tests fix it with `Carbon::setTestNow()`.
     */
    protected function letterDate(): string
    {
        $format = config('cover_letter.generation.date_format', 'F j, Y');
        $format = is_string($format) && trim($format) !== '' ? trim($format) : 'F j, Y';

        return now()->format($format);
    }

    /**
     * The candidate's roles, most recent first, with the achievements the letter
     * may draw on.
     *
     * A role with neither employer nor title is dropped: the model cannot write
     * about a job it cannot name.
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
                'achievements' => $this->stringList($entry['achievements'] ?? null),
            ];
        }

        return array_slice($roles, 0, max(1, $this->limit('max_experience_entries', 4)));
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
                // The profile spells this `fieldOfStudy`; both spellings are
                // accepted because `Api\ProfileController` accepts hand-edited
                // profiles.
                'field' => $this->text($entry['fieldOfStudy'] ?? $entry['field'] ?? null),
            ];
        }

        return $schools;
    }

    /**
     * Every skill the profile claims, flattened.
     *
     * Flat rather than grouped, unlike the resume projection: the letter renders
     * no skills section, so the groups have no display meaning here — this list
     * exists only to tell the model what the candidate may claim to know.
     * Handles both shapes the profile can hold (the parser's
     * `{primary: [...], secondary: [...]}` and a hand-edited flat list).
     *
     * @return array<int, string>
     */
    protected function profileSkills(UserProfile $profile): array
    {
        $skills = [];

        foreach ($this->arrayOf($profile->skills) as $value) {
            foreach (is_array($value) ? $this->stringList($value) : [$this->text($value)] as $skill) {
                if ($skill !== '') {
                    $skills[] = $skill;
                }
            }
        }

        return array_values(array_unique($skills));
    }

    protected function candidateName(UserProfile $profile): string
    {
        // The relation may be unloaded (or absent, for an unsaved profile), and a
        // missing name is not worth failing a render over — the template treats
        // both the letterhead and the signature as optional.
        return $this->text($profile->user?->name ?? null);
    }

    /**
     * Email, location and links, in the order they read best in a letterhead.
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
     * The candidate data the prompt sees.
     *
     * Contact details are excluded, as in resume tailoring: a letterhead the
     * model cannot influence is a letterhead it does not need to see, and an
     * email address in a prompt is one more copy of it in a provider's logs.
     *
     * @param  array<string, mixed>  $facts
     */
    protected function candidateData(array $facts): string
    {
        return $this->truncate($this->encode([
            'skills' => $facts['skills'],
            'experience' => $facts['roles'],
            'education' => $facts['education'],
        ]), 'max_profile_chars', 12000);
    }

    /**
     * The description the letter is written against.
     *
     * An empty description is a failure rather than a prompt: a letter written
     * against nothing is confident generic flattery that looks tailored, which is
     * worse than no letter — and worse here than in resume tailoring, because a
     * generic letter is *obviously* generic to the person reading it. Enrichment
     * (Requirement 3.4) fills this in, so a listing reaching here empty is a
     * pipeline-ordering bug.
     *
     * @throws CoverLetterTailoringException
     */
    protected function descriptionFor(JobListing $listing): string
    {
        $description = trim((string) $listing->description);

        if ($description === '') {
            throw CoverLetterTailoringException::nothingToWrite(
                'the job listing has no description to write against; it needs enrichment first'
            );
        }

        return $this->truncate($description, 'max_description_chars', 12000);
    }

    /* ---------------------------------------------------------------------
     | Assembly: model prose into the template's contract
     | -------------------------------------------------------------------- */

    /**
     * Fold the model's response into the factual skeleton and produce the
     * template's content array.
     *
     * Every key the template reads is present, and the ones with nothing behind
     * them are `null` or `[]` rather than omitted. That is not cosmetic: the
     * template's fallback branches test exactly those values (`trim((string)
     * ($recipient_name ?? ''))`), so a stable shape and a working "Dear Hiring
     * Manager," are the same fact. It also means a caller can read
     * `$content['recipient_name']` and get a definite answer about whether the
     * letter is addressed to anyone.
     *
     * @param  array<mixed>  $parsed
     * @param  array<string, mixed>  $facts
     *
     * @throws MalformedTailoringPayload when the response is decodable but unusable
     */
    protected function assemble(
        array $parsed,
        array $facts,
        ModelCompletionResult $result,
        int $attempt,
    ): TailoredCoverLetterContent {
        $paragraphs = $this->bodyParagraphs($parsed);
        $closing = $this->closingLine($parsed);

        return new TailoredCoverLetterContent(
            content: [
                // From the profile.
                'name' => $facts['name'],
                'contact' => $facts['contact'],
                // From the clock, once, so a re-render does not move it.
                'date' => $facts['date'],
                // From the caller, or nowhere. The model has no field for these,
                // so there is nothing to overwrite them with.
                'recipient_name' => $facts['recipientName'],
                'recipient_title' => $facts['recipientTitle'],
                'company_address' => $facts['companyAddress'],
                // From the listing.
                'company' => $facts['company'],
                'job_title' => $facts['jobTitle'],
                // The model's whole contribution.
                'paragraphs' => $paragraphs,
                // Null lets the template's "Sincerely," fallback apply, which is
                // where that rule belongs.
                'closing' => $closing,
                // Likewise: the template signs with `name` when this is absent,
                // and the candidate's own name is the only correct signature.
                'signature' => null,
            ],
            // What task 11.5's fabrication guard diffs the rendered letter
            // against. None of it passed through a model.
            facts: [
                'name' => $facts['name'],
                'company' => $facts['company'],
                'job_title' => $facts['jobTitle'],
                'date' => $facts['date'],
                'recipient_name' => $facts['recipientName'],
            ],
            rawOutput: $result->content,
            modelUsed: $result->modelUsed,
            tierUsed: $result->tierUsed,
            attempts: $attempt,
        );
    }

    /**
     * The body, validated and bounded.
     *
     * Two bounds with two different jobs. `max_paragraphs` is the one-page
     * guarantee and it *truncates*, because five good paragraphs are four good
     * paragraphs plus one, and re-prompting would spend a call to be told the
     * same thing. `min_paragraphs` *rejects*, because a one-paragraph response is
     * not a short letter — it is an opening line with no argument and no close,
     * which means the model answered a different question, and that is worth the
     * one stricter retry.
     *
     * @param  array<mixed>  $parsed
     * @return array<int, string>
     *
     * @throws MalformedTailoringPayload
     */
    protected function bodyParagraphs(array $parsed): array
    {
        $raw = $this->stringList($parsed['paragraphs'] ?? null);

        if ($raw === []) {
            throw new MalformedTailoringPayload(
                'Cover letter output contains no usable `paragraphs` (got '
                .$this->describe($parsed['paragraphs'] ?? null).').'
            );
        }

        $minParagraphs = $this->minParagraphs();

        if (count($raw) < $minParagraphs) {
            throw new MalformedTailoringPayload(sprintf(
                'Cover letter output has %d paragraph(s); a letter needs at least %d '
                .'(an opening, an argument and a close).',
                count($raw),
                $minParagraphs,
            ));
        }

        $maxChars = $this->limit('max_paragraph_chars', 900);
        $paragraphs = [];

        foreach (array_slice($raw, 0, $this->maxParagraphs()) as $position => $paragraph) {
            $paragraphs[] = $this->plainText($paragraph, "paragraphs[{$position}]", $maxChars);
        }

        return $paragraphs;
    }

    /**
     * The model's sign-off, or null to let the template supply one.
     *
     * @param  array<mixed>  $parsed
     *
     * @throws MalformedTailoringPayload
     */
    protected function closingLine(array $parsed): ?string
    {
        $closing = $parsed['closing'] ?? null;
        $closing = is_string($closing) ? trim($closing) : '';

        if ($closing === '') {
            return null;
        }

        return $this->plainText($closing, 'closing', $this->limit('max_closing_chars', 40));
    }

    /* ---------------------------------------------------------------------
     | Payload validation helpers
     | -------------------------------------------------------------------- */

    /**
     * A model-written string, confirmed to be prose and bounded in length.
     *
     * The markup check is a *rejection*, not a sanitisation, and the resume
     * service's counterpart explains why at length: the template escapes
     * everything, so a smuggled `\input{}` is harmless to the compiler and would
     * simply *print*, which means the letter would ship with `\textbf{Led} a
     * team` on it. The escaper protects the compiler; this protects the content.
     *
     * Markdown is checked here and not there, and that difference is the point of
     * the field: `**genuinely excited**` in a bullet is a formatting slip, but in
     * a letter's running prose it is a visible typographic error in the middle of
     * a sentence a human will read as the candidate's own writing. Same verdict,
     * same one stricter retry.
     *
     * @throws MalformedTailoringPayload
     */
    protected function plainText(string $value, string $field, int $maxChars): string
    {
        if (preg_match(self::LATEX_COMMAND_PATTERN, $value) === 1) {
            throw new MalformedTailoringPayload(sprintf(
                'Cover letter output field `%s` contains LaTeX markup, which is not allowed: "%s". '
                .'The document template applies all formatting.',
                $field,
                Str::limit($value, 120)
            ));
        }

        if (preg_match(self::MARKDOWN_PATTERN, $value) === 1) {
            throw new MalformedTailoringPayload(sprintf(
                'Cover letter output field `%s` contains Markdown formatting, which is not allowed: '
                .'"%s". A cover letter is plain prose.',
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
     * A list of non-empty trimmed strings from anything.
     *
     * Non-string entries are dropped rather than stringified: a nested object
     * where a paragraph was expected means the model answered a different
     * question, and the surviving strings are still usable. De-duplicated,
     * because a model that repeats a paragraph verbatim has padded the letter
     * rather than written one.
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
            is_array($value) => 'an array of '.count($value).' item(s), none of them usable text',
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

    protected function maxParagraphs(): int
    {
        return max(1, $this->limit('max_paragraphs', 4));
    }

    /** Never above {@see maxParagraphs()}, so a mis-typed config cannot make every response unusable. */
    protected function minParagraphs(): int
    {
        return max(1, min($this->maxParagraphs(), $this->limit('min_paragraphs', 2)));
    }

    protected function limit(string $key, int $default): int
    {
        return (int) config("cover_letter.generation.{$key}", $default);
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

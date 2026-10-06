<?php

namespace App\Services\Tailoring;

use App\Models\UserProfile;
use App\Services\Latex\LatexEscaper;

/**
 * The post-generation check of Requirement 6.6: diff the employer, title and
 * date tokens in a tailored document against the profile that sourced them, and
 * report anything the profile does not support so the caller can route the run
 * to {@see \App\Enums\PipelineStage::NeedsReview} instead of auto-applying with
 * it (Requirement 9.4).
 *
 * ## What is honestly checkable here, and what is theatre
 *
 * This is the part worth being blunt about, because a naive reading of Req 6.6
 * produces a check that can never fail.
 *
 * {@see ResumeTailoringService} does not let the model restate a fact at all.
 * Employer names, titles, date ranges, schools and contact details are projected
 * from {@see UserProfile} *before* the model is called, and the response schema
 * has no field for any of them — the model contributes a summary, a headline,
 * bullet strings bound to a role by index, and a skill ordering. So "diff the
 * document's employer tokens against the profile's employer tokens" compares a
 * value against the variable it was assigned from. It passes by construction,
 * and a guard consisting only of that would be a green light nobody had earned.
 *
 * Three things are genuinely worth checking, and this class checks exactly those:
 *
 *  1. **Do the model-written strings introduce facts of their own?** Bullets are
 *     the one place model wording touches employment history. A bullet naming an
 *     organisation the profile never mentions, or stating a year outside the
 *     span the profile describes, is a real fabrication and the only kind this
 *     architecture can still produce. This is the check that earns the class.
 *  2. **Did the profile's facts survive into the rendered document?** A fact that
 *     silently vanished in the merge or in a template revision leaves a resume
 *     that is *less* true than the profile — an employer with no name over it —
 *     and that must not be auto-submitted either. It is not fabrication, so it
 *     is reported under its own type
 *     ({@see \App\Enums\FabricationFindingType::MissingFact}) with the
 *     "this is our bug" flag set.
 *  3. **Did the merge invent a fact?** Cheap, and it can only fail on a defect in
 *     our own code rather than on anything a model did. Kept anyway, because it
 *     is the literal wording of the requirement and because the day
 *     {@see TailoredResumeContent::$facts} and the profile disagree is the day
 *     somebody wants to have been told.
 *
 * ## Why the *content* is scanned and the `.tex` is only spot-checked
 *
 * The rendered source is LaTeX-escaped by {@see LatexEscaper} (it is the echo
 * format of the template dialect), so a profile value never appears in it
 * verbatim: `Analytical & Co` is written `Analytical \& Co`, and a substring
 * search for the raw value reports a match failure on a document that is
 * perfectly correct. Escaping is also lossy in the direction a scanner cares
 * about — `\textbackslash{}` and `\%` add braces and backslashes that are
 * indistinguishable from the template's own markup, so re-deriving the plain
 * text from the source is guesswork.
 *
 * Therefore: the fabrication detectors run on the *pre-render* content, where
 * strings are exactly what the model wrote, and the presence check on the `.tex`
 * escapes the expected token the same way the template did before searching for
 * it. Each side compares like with like, and neither has to unescape anything.
 *
 * ## Not crying wolf
 *
 * A guard that flags good bullets is worse than no guard, because the review
 * queue becomes noise and the one real finding goes unread. Two deliberate
 * concessions, both configured in `config('pipeline.fabrication')`:
 *
 *  - An organisation is only recognised behind an organisational suffix
 *    ("Globex Inc", "Initech Holdings") or directly after "at". "Reduced
 *    Kubernetes rollout time" names a tool, and a detector keyed on
 *    capitalisation alone would flag every technology in the document.
 *  - A four-digit number is only a date behind a date cue, or inside a range.
 *    "scaled to 2000 tenants" and "cut p99 by 40%" state quantities.
 *  - The window of supported years is the profile's own span widened by
 *    `date_window_slack_years`, so "grew revenue 40% in 2023" against a role the
 *    profile dates 2022-2024 is clean — and so is a bullet one year off a
 *    boundary, since the profile's own date text is coarse (a month count pins
 *    no year at all).
 *
 * The trade is intentional: this guard under-detects rather than over-detects.
 * It is the last automated check before something is sent out in the user's
 * name, and the design already expects a human to sample tailored resumes before
 * the threshold here is trusted (design.md §"Manual verification").
 *
 * ## Why it is a standalone collaborator and not part of the pipeline
 *
 * {@see ResumeTailoringPipeline} owns one thing — generate, render, and re-prompt
 * on a compile failure — and both its docblock and {@see CompiledResume}'s draw
 * this guard explicitly outside that boundary. Wiring it in would mean
 * {@see CompiledResume} carrying a report, which changes a value object two other
 * tasks already consume, and it would put a policy decision ("is this document
 * fit to send?") inside a class whose whole contract is "a PDF exists". The
 * queued `TailorResume` job of task 11.7 already holds the listing, the profile
 * and the compiled resume; it calls {@see inspectCompiled()} in one line and is
 * the only place that can act on the answer. So the guard stays inert and
 * injectable, which is also what makes it testable with neither a database nor a
 * TeX engine.
 */
class FabricationGuard
{
    /**
     * One word of a proper name: capitalised, and allowed to carry `&`, an
     * apostrophe or a hyphen ("Analytical & Co", "O'Reilly", "Hewlett-Packard").
     *
     * A full stop is deliberately excluded. Including it would let a name run
     * across a sentence boundary — "…churn at Globex. Then we…" would capture
     * "Globex. Then" — and the cost is only that dotted abbreviations ("U.S.
     * Bank") are detected from their last component onwards. Under-detection is
     * the acceptable direction here; a candidate stitched out of two sentences is
     * a finding nobody can act on.
     */
    private const NAME_WORD = "[A-Z][\\p{L}\\p{N}&'’\\-]*";

    /** Words of a proper name allowed before the organisational suffix. */
    private const MAX_NAME_WORDS = 4;

    /**
     * Shortest candidate worth reporting.
     *
     * Two characters is an initialism ("at GE") that is as likely to be a team,
     * a tool or a unit as a company; the profile rarely spells those out, so
     * they generate noise rather than findings.
     */
    private const MIN_CANDIDATE_LENGTH = 3;

    /**
     * Inspect a tailoring run before or after it was rendered.
     *
     * @param  ?UserProfile  $profile  the profile the run was built from. Optional, but
     *                                 supplying it is what makes the employer check
     *                                 accurate: without it the guard only knows the
     *                                 employers, titles and dates carried in
     *                                 {@see TailoredResumeContent::$facts}, and a bullet
     *                                 mentioning a school, a tool or a client the profile
     *                                 does elsewhere claim has nothing to be matched
     *                                 against. Every production caller has it.
     * @param  ?string  $texSource  the rendered LaTeX, when there is one. Null skips the
     *                              missing-fact check and says so on the report rather
     *                              than reporting a pass.
     */
    public function inspect(
        TailoredResumeContent $tailored,
        ?UserProfile $profile = null,
        ?string $texSource = null,
    ): FabricationReport {
        $source = $this->sourceVocabulary($tailored, $profile);

        $findings = array_merge(
            $this->mergeIntegrityFindings($tailored, $profile),
            $this->renderedDocumentFindings($tailored, $texSource),
            $this->generatedTextFindings($tailored, $source),
        );

        $max = $this->maxFindings();
        $truncated = max(0, count($findings) - $max);

        return new FabricationReport(
            findings: array_slice($findings, 0, $max),
            datesVerifiable: $source['years'] !== [],
            texScanned: $texSource !== null,
            truncated: $truncated,
        );
    }

    /** The same inspection, for the output of {@see ResumeTailoringPipeline}. */
    public function inspectCompiled(CompiledResume $compiled, ?UserProfile $profile = null): FabricationReport
    {
        return $this->inspect($compiled->tailored, $profile, $compiled->texSource());
    }

    /* ------------------------------------------------------------------
     | 1. The facts the merge produced really came from the profile
     | ----------------------------------------------------------------- */

    /**
     * Every employer and title in {@see TailoredResumeContent::$facts} appears in
     * `UserProfile.experience`.
     *
     * This is the requirement's literal diff, and it can only fail on a defect in
     * {@see ResumeTailoringService::mergeExperience()} or in the projection ahead
     * of it — the model has no field that reaches these values. Said plainly
     * rather than dressed up as fabrication detection: it is a cheap assertion
     * that the one-source-of-truth property still holds, and it is reported as an
     * unknown employer because from a reviewer's seat that is what a resume with
     * an employer the profile does not list *is*.
     *
     * @return array<int, FabricationFinding>
     */
    protected function mergeIntegrityFindings(TailoredResumeContent $tailored, ?UserProfile $profile): array
    {
        if (! $profile instanceof UserProfile) {
            return [];
        }

        $companies = [];
        $positions = [];

        foreach ($this->experienceEntries($profile) as $entry) {
            $companies[] = $this->normalize((string) ($entry['company'] ?? ''));
            $positions[] = $this->normalize((string) ($entry['position'] ?? ''));
        }

        $findings = [];

        foreach ($tailored->facts as $index => $fact) {
            foreach (['company', 'position'] as $field) {
                $value = trim((string) ($fact[$field] ?? ''));
                $normalized = $this->normalize($value);

                if ($value === '' || $normalized === '') {
                    continue;
                }

                $known = $field === 'company' ? $companies : $positions;

                if (in_array($normalized, $known, true)) {
                    continue;
                }

                $findings[] = FabricationFinding::unknownEmployer(
                    $value,
                    sprintf('role %d (%s field)', $index + 1, $field),
                    sprintf(
                        'The document states the %s "%s", which is not one of the %s values in the profile\'s '
                        .'experience. Employers and titles are copied from the profile and never generated, so '
                        .'this points at a merge defect rather than at the model.',
                        $field,
                        $value,
                        $field,
                    ),
                );
            }
        }

        return $findings;
    }

    /* ------------------------------------------------------------------
     | 2. The profile's facts survived into the rendered document
     | ----------------------------------------------------------------- */

    /**
     * Each fact token is present in the `.tex`, in the form the template would
     * have written it.
     *
     * The expected token is escaped through {@see LatexEscaper} before the search,
     * which is the whole reason this check is trustworthy: the escaper is the
     * template dialect's echo format, so `Analytical & Co` reaches the source as
     * `Analytical \& Co` and a raw substring search would report a missing
     * employer on a flawless document.
     *
     * @return array<int, FabricationFinding>
     */
    protected function renderedDocumentFindings(TailoredResumeContent $tailored, ?string $texSource): array
    {
        if ($texSource === null) {
            return [];
        }

        $findings = [];

        foreach ($tailored->facts as $index => $fact) {
            foreach (['company', 'position', 'dates'] as $field) {
                $value = trim((string) ($fact[$field] ?? ''));

                if ($value === '') {
                    continue;
                }

                if (str_contains($texSource, LatexEscaper::escape($value))) {
                    continue;
                }

                $findings[] = FabricationFinding::missingFact(
                    $value,
                    sprintf('rendered document (role %d %s)', $index + 1, $field),
                    sprintf(
                        'The profile\'s %s "%s" does not appear in the rendered document. The resume is missing '
                        .'a fact the profile supplied, which is a template or merge defect and will recur on a '
                        .'re-run.',
                        $field,
                        $value,
                    ),
                );
            }
        }

        return $findings;
    }

    /* ------------------------------------------------------------------
     | 3. The model-written strings introduce no facts of their own
     | ----------------------------------------------------------------- */

    /**
     * Scan the summary, headline and bullets — the only strings the model wrote —
     * for organisations and years the profile does not support.
     *
     * @param  array{haystack: string, companies: array<int, string>, years: array<int, int>, openEnded: bool}  $source
     * @return array<int, FabricationFinding>
     */
    protected function generatedTextFindings(TailoredResumeContent $tailored, array $source): array
    {
        $findings = [];

        foreach ($this->generatedStrings($tailored) as [$location, $text]) {
            foreach ($this->employerCandidates($text) as $candidate) {
                if ($this->isSupportedEmployer($candidate, $source)) {
                    continue;
                }

                $findings[] = FabricationFinding::unknownEmployer(
                    $candidate,
                    $location,
                    sprintf(
                        'The %s names "%s", which appears nowhere in the profile. Either the model introduced '
                        .'an employer the candidate never worked for, or the profile is missing a company the '
                        .'user expects to see.',
                        $location,
                        $candidate,
                    ),
                );
            }

            if ($source['years'] === []) {
                // Nothing to diff against: the profile pins no calendar year, so
                // every year in a bullet would be "unsupported" and the queue
                // would fill with findings no reviewer could resolve. The report
                // records that the check was inert rather than passing.
                continue;
            }

            [$from, $to] = $this->supportedYearWindow($source);

            foreach ($this->dateYears($text) as $year) {
                if ($year >= $from && $year <= $to) {
                    continue;
                }

                $findings[] = FabricationFinding::unsupportedDate(
                    (string) $year,
                    $location,
                    sprintf(
                        'The %s states the year %d, outside the %d-%d span the profile\'s own dates support '
                        .'(slack included). Either the year is invented or the profile\'s dates are wrong.',
                        $location,
                        $year,
                        $from,
                        $to,
                    ),
                );
            }
        }

        return $findings;
    }

    /**
     * The strings the model contributed, each with a location a human can find.
     *
     * Bullets are labelled with their role's employer as well as their index: a
     * reviewer reads the document, not this array, and "bullet 2" alone means
     * counting.
     *
     * A role the model skipped keeps the candidate's own stored achievements as
     * its bullets (see {@see ResumeTailoringService::mergeExperience()}), so some
     * of these strings are not model-written at all. They are scanned anyway, and
     * pass trivially, because the profile that sourced them is part of the
     * vocabulary they are checked against.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    protected function generatedStrings(TailoredResumeContent $tailored): array
    {
        $content = $tailored->forTemplate();

        $strings = [
            ['summary', (string) ($content['summary'] ?? '')],
            ['headline', (string) ($content['headline'] ?? '')],
        ];

        foreach ($content['experience'] ?? [] as $roleIndex => $role) {
            $employer = trim((string) ($role['company'] ?? $role['position'] ?? ''));

            foreach ($role['bullets'] ?? [] as $bulletIndex => $bullet) {
                $strings[] = [
                    sprintf(
                        'bullet %d of role %d%s',
                        (int) $bulletIndex + 1,
                        (int) $roleIndex + 1,
                        $employer === '' ? '' : ' ('.$employer.')',
                    ),
                    (string) $bullet,
                ];
            }
        }

        return array_values(array_filter($strings, fn (array $pair): bool => trim($pair[1]) !== ''));
    }

    /**
     * Organisation-shaped phrases in one string.
     *
     * Two patterns, both anchored on something that distinguishes a company from
     * a product name, because capitalisation alone does not: an organisational
     * suffix from `pipeline.fabrication.employer_suffixes`, or the preposition
     * "at", which in a resume bullet is followed by an employer or by nothing
     * capitalised at all.
     *
     * @return array<int, string> deduplicated, in the order they appear
     */
    protected function employerCandidates(string $text): array
    {
        $suffixes = array_map(
            fn (string $suffix): string => preg_quote($suffix, '/'),
            $this->employerSuffixes(),
        );

        // Longest first, so "Inc." is preferred over "Inc" and the trailing dot
        // ends up inside the match rather than dangling after it.
        usort($suffixes, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        $name = self::NAME_WORD;
        $repeat = self::MAX_NAME_WORDS - 1;

        $patterns = [
            // "Globex Inc", "Initech Holdings", "Analytical & Co"
            '/\b((?:'.$name.'[ ]+){0,'.$repeat.'}'.$name.')[ ]*,?[ ]+(?:'.implode('|', $suffixes).')(?![\p{L}])/u',
            // "shipped it at Globex"
            '/\b[Aa]t[ ]+('.$name.'(?:[ ]+(?:'.$name.'|&))*)/u',
        ];

        $candidates = [];

        foreach ($patterns as $index => $pattern) {
            if (preg_match_all($pattern, $text, $matches) === false) {
                continue;
            }

            foreach ($matches[0] as $position => $whole) {
                // The suffix pattern reports the whole phrase (a reviewer needs
                // "Globex Inc", not "Globex"); the "at" pattern reports only what
                // followed the preposition.
                $candidate = trim($index === 0 ? $whole : $matches[1][$position]);
                $candidate = trim($candidate, " \t\n\r,.;:");

                if (! $this->isPlausibleOrganisation($candidate)) {
                    continue;
                }

                $candidates[$this->normalize($candidate)] = $candidate;
            }
        }

        return array_values($candidates);
    }

    /**
     * Filter for things that matched an organisation pattern without being one.
     *
     * The "at" pattern is the reason this exists: "at Q3", "at 2019", "at AI" are
     * all capitalised words after a preposition, and none of them is a company a
     * profile would list.
     */
    protected function isPlausibleOrganisation(string $candidate): bool
    {
        $normalized = $this->normalize($candidate);

        if (mb_strlen($normalized) < self::MIN_CANDIDATE_LENGTH) {
            return false;
        }

        // A date, a quarter or a bare number is a time reference; the date
        // detector below owns those.
        if (preg_match('/\d/', $normalized) === 1) {
            return false;
        }

        return ! in_array($normalized, $this->dateCues(), true);
    }

    /**
     * Does the profile mention this organisation anywhere?
     *
     * Substring matching on a normalized haystack, in both directions, because
     * the same employer is written several ways across a profile: the experience
     * entry says "Analytical & Co", the resume text says "Analytical and Co.",
     * and a bullet says "Analytical". A finding is only raised when *no* form
     * relates the candidate to any source string — the check errs towards
     * supported, per the class docblock.
     *
     * @param  array{haystack: string, companies: array<int, string>, years: array<int, int>, openEnded: bool}  $source
     */
    protected function isSupportedEmployer(string $candidate, array $source): bool
    {
        $normalized = $this->normalize($candidate);

        if ($normalized === '') {
            return true;
        }

        $bare = $this->stripOrganisationSuffix($normalized);

        foreach (array_unique([$normalized, $bare]) as $form) {
            if ($form === '') {
                continue;
            }

            if ($source['haystack'] !== '' && str_contains($source['haystack'], $form)) {
                return true;
            }

            foreach ($source['companies'] as $company) {
                if ($company !== '' && str_contains($form, $company)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** "globex inc" -> "globex", so a suffix the profile omits is not a mismatch. */
    protected function stripOrganisationSuffix(string $normalized): string
    {
        $suffixes = array_map(fn (string $suffix): string => $this->normalize($suffix), $this->employerSuffixes());
        $words = explode(' ', $normalized);

        while ($words !== [] && in_array(end($words), $suffixes, true)) {
            array_pop($words);
        }

        return implode(' ', $words);
    }

    /* ------------------------------------------------------------------
     | Dates
     | ----------------------------------------------------------------- */

    /**
     * Years stated as dates in one string.
     *
     * A four-digit number counts only when a date cue precedes it ("in 2023",
     * "since 2018", "March 2019") or when it sits in a range ("2019-2021",
     * "2019 to present"). Everything else is a quantity: a resume is full of
     * "2000 tenants", "1200 requests per second" and "40%", and flagging those
     * would make the guard useless within a day.
     *
     * The residual false positive is a count that follows a cue and lands inside
     * the plausible-year range — "resulting in 2000 signups". `min_plausible_year`
     * bounds how often that can happen (a count below it is not considered at
     * all), and distinguishing the two properly needs the noun after the number,
     * which is more grammar than a guard should carry.
     *
     * @return array<int, int> plausible years, deduplicated
     */
    protected function dateYears(string $text): array
    {
        $years = [];

        $rangePattern = '/\b((?:19|20)\d{2})[ ]*(?:-|–|—|to|until|through|thru)[ ]*'
            .'((?:19|20)\d{2}|present|current|now|today|ongoing)\b/iu';

        if (preg_match_all($rangePattern, $text, $matches) > 0) {
            foreach ($matches[1] as $position => $start) {
                $years[] = (int) $start;

                if (preg_match('/^\d{4}$/', $matches[2][$position]) === 1) {
                    $years[] = (int) $matches[2][$position];
                }
            }
        }

        $cues = array_map(fn (string $cue): string => preg_quote($cue, '/'), $this->dateCues());
        usort($cues, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        $cuePattern = '/\b(?:'.implode('|', $cues).')\b[ :.\-]*((?:19|20)\d{2})\b/iu';

        if (preg_match_all($cuePattern, $text, $matches) > 0) {
            foreach ($matches[1] as $year) {
                $years[] = (int) $year;
            }
        }

        $minimum = $this->minPlausibleYear();
        $maximum = (int) date('Y') + $this->futureYearSlack();

        $years = array_filter($years, fn (int $year): bool => $year >= $minimum && $year <= $maximum);

        return array_values(array_unique($years));
    }

    /**
     * The span of years a bullet may refer to without being a finding.
     *
     * The profile's own minimum and maximum, widened by
     * `date_window_slack_years` at both ends, and extended to today when any
     * profile date is open-ended ("2021 - Present"): a bullet about work done in
     * the current role names a year the profile's text never spells out.
     *
     * A window rather than per-role ranges, deliberately. Bullets are bound to a
     * role by index, but the *summary* is bound to nothing, cross-role
     * achievements are normal ("led the migration across both teams"), and a
     * reviewer cannot act on "2019 is valid for role 2 but you put it under role
     * 1" — that is an editorial note, not a fabrication. Widening to the whole
     * career keeps every finding a claim the candidate's history genuinely does
     * not contain.
     *
     * @param  array{haystack: string, companies: array<int, string>, years: array<int, int>, openEnded: bool}  $source
     * @return array{0: int, 1: int}
     */
    protected function supportedYearWindow(array $source): array
    {
        $slack = $this->dateWindowSlack();
        $latest = max($source['years']);

        if ($source['openEnded']) {
            $latest = max($latest, (int) date('Y'));
        }

        return [min($source['years']) - $slack, $latest + $slack];
    }

    /* ------------------------------------------------------------------
     | The profile side
     | ----------------------------------------------------------------- */

    /**
     * Everything the profile can be said to claim, in the two forms the checks
     * need: one normalized haystack for substring matching, the employer names on
     * their own, and the calendar years the profile pins down.
     *
     * The facts carried on {@see TailoredResumeContent} are always included, so
     * the guard degrades to a narrower but still meaningful check when no profile
     * is supplied. When one is, its achievements, skills, education and raw
     * resume text join the haystack — a bullet mentioning the user's university,
     * a tool they list, or a client named in their own resume text is supported
     * by the profile even though it is not an employer, and flagging it would be
     * exactly the noise this guard is built to avoid.
     *
     * The *years*, unlike the names, come only from the employment date fields.
     * Education years and stray years in `resume_text` belong to a different
     * timeline: a graduation year of 2009 would widen the window by thirteen
     * years and make a bullet claiming work in 2014, for a career the profile
     * starts in 2022, indistinguishable from a true one. Bullets describe
     * employment, so employment dates are what they are checked against.
     *
     * @return array{haystack: string, companies: array<int, string>, years: array<int, int>, openEnded: bool}
     */
    protected function sourceVocabulary(TailoredResumeContent $tailored, ?UserProfile $profile): array
    {
        $texts = [];
        $companies = [];
        $dateTexts = [];

        foreach ($tailored->facts as $fact) {
            $company = (string) ($fact['company'] ?? '');
            $texts[] = $company;
            $texts[] = (string) ($fact['position'] ?? '');
            $dateTexts[] = (string) ($fact['dates'] ?? '');

            if ($this->normalize($company) !== '') {
                $companies[] = $this->normalize($company);
            }
        }

        if ($profile instanceof UserProfile) {
            foreach ($this->experienceEntries($profile) as $entry) {
                $company = (string) ($entry['company'] ?? '');
                $texts[] = $company;
                $texts[] = (string) ($entry['position'] ?? '');
                $texts[] = $this->flatten($entry['achievements'] ?? null);
                $dateTexts[] = is_array($entry['duration'] ?? null) ? '' : (string) ($entry['duration'] ?? '');

                if ($this->normalize($company) !== '') {
                    $companies[] = $this->normalize($company);
                }
            }

            $texts[] = $this->flatten($profile->education);
            $texts[] = $this->flatten($profile->skills);
            $texts[] = $this->flatten($profile->parsed_keywords ?? null);
            $texts[] = (string) ($profile->resume_text ?? '');
        }

        $dateText = implode(' ', array_filter($dateTexts, fn (string $value): bool => trim($value) !== ''));

        return [
            'haystack' => implode(' | ', array_values(array_filter(
                array_map(fn (string $value): string => $this->normalize($value), $texts),
                fn (string $value): bool => $value !== '',
            ))),
            'companies' => array_values(array_unique($companies)),
            'years' => $this->yearsIn($dateText),
            'openEnded' => preg_match('/\b(present|current|currently|now|to date|ongoing)\b/i', $dateText) === 1,
        ];
    }

    /**
     * Every plausible year in the profile's date text, cue or no cue.
     *
     * Unlike {@see dateYears()} this takes any four-digit year it finds, because
     * every string here is a date field: `"2019 - 2021"`, `"Jan 2019 to present"`
     * or `"2 yr 6 mo"` carry no cue words to require, and demanding one would find
     * nothing. The failure mode of being generous on this side is a *wider*
     * supported window, which under-detects — the direction this guard prefers.
     *
     * @return array<int, int>
     */
    protected function yearsIn(string $text): array
    {
        if (preg_match_all('/\b((?:19|20)\d{2})\b/', $text, $matches) < 1) {
            return [];
        }

        $minimum = $this->minPlausibleYear();
        $maximum = (int) date('Y') + $this->futureYearSlack();

        $years = array_filter(
            array_map('intval', $matches[1]),
            fn (int $year): bool => $year >= $minimum && $year <= $maximum,
        );

        return array_values(array_unique($years));
    }

    /**
     * `user_profiles.experience` as a list of arrays, whatever shape it is in.
     *
     * The column is a JSON array cast, so it can hold anything a client posted;
     * non-array entries are skipped for the same reason
     * {@see ResumeTailoringService::factualRoles()} skips them.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function experienceEntries(UserProfile $profile): array
    {
        $entries = [];

        foreach (is_array($profile->experience) ? $profile->experience : [] as $entry) {
            if (is_array($entry)) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /** Any nested profile value as one whitespace-joined string. */
    protected function flatten(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (! is_array($value)) {
            return '';
        }

        $parts = [];

        foreach ($value as $item) {
            $flat = $this->flatten($item);

            if (trim($flat) !== '') {
                $parts[] = $flat;
            }
        }

        return implode(' ', $parts);
    }

    /**
     * Case, punctuation and whitespace folded, so "Analytical & Co." and
     * "analytical  &  co" compare equal.
     *
     * `&` survives because it is part of company names often enough to matter,
     * and folding it away would make "Smith & Co" and "Smith Co" indistinguish-
     * able from "Smithco" — which is fine for matching but confusing in a
     * finding's token.
     */
    protected function normalize(string $value): string
    {
        $value = mb_strtolower($value);
        $value = str_replace(['’', '‘', '`', '´'], "'", $value);
        $value = (string) preg_replace('/[^\p{L}\p{N}&\s]+/u', ' ', $value);

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /* ------------------------------------------------------------------
     | Config
     | ----------------------------------------------------------------- */

    /** @return array<int, string> */
    protected function employerSuffixes(): array
    {
        $suffixes = config('pipeline.fabrication.employer_suffixes', []);

        return array_values(array_filter(
            array_map(fn (mixed $value): string => trim((string) $value), is_array($suffixes) ? $suffixes : []),
            fn (string $value): bool => $value !== '',
        ));
    }

    /** @return array<int, string> normalized, so they compare against normalized text */
    protected function dateCues(): array
    {
        $cues = config('pipeline.fabrication.date_cues', []);

        return array_values(array_unique(array_filter(
            array_map(fn (mixed $value): string => $this->normalize((string) $value), is_array($cues) ? $cues : []),
            fn (string $value): bool => $value !== '',
        )));
    }

    protected function minPlausibleYear(): int
    {
        return max(1, (int) config('pipeline.fabrication.min_plausible_year', 1950));
    }

    protected function futureYearSlack(): int
    {
        return max(0, (int) config('pipeline.fabrication.future_year_slack_years', 1));
    }

    protected function dateWindowSlack(): int
    {
        return max(0, (int) config('pipeline.fabrication.date_window_slack_years', 1));
    }

    protected function maxFindings(): int
    {
        return max(1, (int) config('pipeline.fabrication.max_findings', 25));
    }
}

<?php

namespace Tests\Unit\Services\Tailoring;

use App\Enums\FabricationFindingType;
use App\Models\UserProfile;
use App\Services\Latex\LatexEscaper;
use App\Services\Tailoring\FabricationGuard;
use App\Services\Tailoring\TailoredResumeContent;
use Tests\TestCase;

/**
 * The fabrication guard of Requirement 6.6 (task 11.5).
 *
 * No database, no model router, no TeX engine: the guard's whole input is a
 * {@see TailoredResumeContent}, an unsaved {@see UserProfile} and — where the
 * rendered document is under test — a hand-written LaTeX string. That is
 * deliberate on the guard's part rather than convenient here: it reports and does
 * not act, so there is nothing to persist and nothing to fake.
 *
 * Half of these tests assert that something is *not* flagged. That is the harder
 * half of this feature — a guard that flags a legitimate bullet fills the review
 * queue with noise until nobody reads it — so the metric, the technology name and
 * the in-range year each get their own case.
 */
class FabricationGuardTest extends TestCase
{
    private FabricationGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->guard = new FabricationGuard;
    }

    /* ------------------------------------------------------------------
     | Fixtures
     | ----------------------------------------------------------------- */

    /**
     * A tailoring result, built the way {@see \App\Services\Tailoring\ResumeTailoringService}
     * builds one: the facts mirror the content's roles, in the same order.
     *
     * @param  array<int, array<string, mixed>>  $roles  each `{company, position, dates, bullets}`
     */
    private function tailoredContent(
        array $roles,
        string $summary = 'Backend engineer building Laravel services on PostgreSQL.',
        string $headline = 'Senior Backend Engineer',
    ): TailoredResumeContent {
        $experience = [];
        $facts = [];

        foreach ($roles as $role) {
            $experience[] = [
                'position' => (string) ($role['position'] ?? 'Staff Engineer'),
                'company' => (string) ($role['company'] ?? 'Analytical & Co'),
                'location' => '',
                'dates' => (string) ($role['dates'] ?? '2022 - 2024'),
                'bullets' => array_values($role['bullets'] ?? []),
            ];

            $facts[] = [
                'company' => (string) ($role['company'] ?? 'Analytical & Co'),
                'position' => (string) ($role['position'] ?? 'Staff Engineer'),
                'dates' => (string) ($role['dates'] ?? '2022 - 2024'),
            ];
        }

        return new TailoredResumeContent(
            content: [
                'name' => 'Ada Lovelace',
                'headline' => $headline,
                'contact' => ['ada@example.com'],
                'summary' => $summary,
                'skills' => [['label' => 'Core Skills', 'items' => ['Laravel', 'PostgreSQL', 'Kubernetes']]],
                'experience' => $experience,
                'education' => [],
            ],
            facts: $facts,
            droppedSkills: [],
            rawOutput: '{}',
            modelUsed: 'vendor/standard-1',
            tierUsed: 'standard',
        );
    }

    /**
     * The profile the tailoring came from. `duration` is free text here, which is
     * the shape a hand-edited profile has and the only shape that pins calendar
     * years — see {@see test_a_profile_whose_dates_pin_no_year_disables_the_date_check}
     * for the other one.
     */
    private function profile(array $overrides = []): UserProfile
    {
        return new UserProfile(array_merge([
            'user_id' => 7,
            'skills' => ['primary' => ['Laravel', 'PostgreSQL'], 'secondary' => ['Kubernetes', 'Redis']],
            'experience' => [
                [
                    'company' => 'Analytical & Co',
                    'position' => 'Staff Engineer',
                    'duration' => '2022 - 2024',
                    'achievements' => ['Owned the billing service'],
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
        ], $overrides));
    }

    /** A minimal document containing the escaped facts, as the template would write them. */
    private function texFor(TailoredResumeContent $tailored): string
    {
        $lines = ['\documentclass{article}', '\begin{document}'];

        foreach ($tailored->facts as $fact) {
            $lines[] = '\textbf{'.LatexEscaper::escape($fact['position']).'}';
            $lines[] = LatexEscaper::escape($fact['company']).' \hfill '.LatexEscaper::escape($fact['dates']);
        }

        return implode("\n", $lines)."\n".'\end{document}';
    }

    /* ------------------------------------------------------------------
     | The clean case
     | ----------------------------------------------------------------- */

    public function test_a_faithful_tailoring_run_produces_no_findings(): void
    {
        $tailored = $this->tailoredContent([
            [
                'bullets' => [
                    'Owned the billing service through a rewrite in 2023',
                    'Reduced p99 latency by 40% while at Analytical & Co',
                ],
            ],
        ]);

        $report = $this->guard->inspect($tailored, $this->profile(), $this->texFor($tailored));

        $this->assertTrue($report->isClean(), $report->reviewReason());
        $this->assertFalse($report->requiresReview());
        $this->assertSame([], $report->findings);

        // A clean report says what it was able to check, so "no findings" cannot
        // be misread as "the dates were verified" when they were not.
        $this->assertTrue($report->datesVerifiable);
        $this->assertTrue($report->texScanned);
        $this->assertSame('', $report->reviewReason());
        $this->assertTrue($report->flags()['clean']);
    }

    public function test_technology_and_metric_wording_is_not_mistaken_for_a_fact(): void
    {
        // Every trap in one document: capitalised tool names, a percentage, a
        // large count that sits in the plausible-year range, and a currency
        // figure. None of these is an employer or a date.
        $tailored = $this->tailoredContent(
            [[
                'bullets' => [
                    'Scaled the Kubernetes fleet to 2000 tenants without downtime',
                    'Cut PostgreSQL replication lag by 40% and saved $1200 a month',
                    'Handled 1975 requests per second at peak',
                ],
            ]],
            summary: 'Engineer with Laravel, Redis and Kubernetes depth.',
        );

        $report = $this->guard->inspect($tailored, $this->profile(), $this->texFor($tailored));

        $this->assertTrue($report->isClean(), $report->reviewReason());
    }

    /* ------------------------------------------------------------------
     | Employers the profile does not know
     | ----------------------------------------------------------------- */

    public function test_a_bullet_naming_an_employer_absent_from_the_profile_is_flagged(): void
    {
        $tailored = $this->tailoredContent([[
            'bullets' => ['Led the platform migration for Globex Inc across three regions'],
        ]]);

        $report = $this->guard->inspect($tailored, $this->profile(), $this->texFor($tailored));

        $this->assertTrue($report->requiresReview());
        $this->assertTrue($report->has(FabricationFindingType::UnknownEmployer));

        $finding = $report->ofType(FabricationFindingType::UnknownEmployer)[0];
        $this->assertSame('Globex Inc', $finding->token);
        $this->assertStringContainsString('bullet 1 of role 1', $finding->location);
        $this->assertStringContainsString('Analytical & Co', $finding->location);
        $this->assertFalse($finding->type->indicatesCodeDefect());

        // The reason is the review item's call-to-action (Req 9.4.3), so it has
        // to name the token and where to find it.
        $this->assertStringContainsString('Globex Inc', $report->reviewReason());
    }

    public function test_an_employer_introduced_by_the_summary_is_flagged_with_its_own_location(): void
    {
        $tailored = $this->tailoredContent(
            [['bullets' => ['Owned the billing service']]],
            summary: 'Backend engineer, previously at Initech Holdings, now building Laravel services.',
        );

        $report = $this->guard->inspect($tailored, $this->profile());

        $findings = $report->ofType(FabricationFindingType::UnknownEmployer);
        $this->assertCount(1, $findings);
        $this->assertSame('Initech Holdings', $findings[0]->token);
        $this->assertSame('summary', $findings[0]->location);
    }

    public function test_the_profiles_own_employer_named_in_a_bullet_is_not_flagged(): void
    {
        // Same phrasing that trips the "at" detector, against a company the
        // profile does claim — including a punctuation and suffix variant the
        // profile spells differently.
        $tailored = $this->tailoredContent([[
            'bullets' => [
                'Rebuilt the ledger at Analytical & Co',
                'Mentored four engineers at Analytical and Co.',
            ],
        ]]);

        $report = $this->guard->inspect($tailored, $this->profile());

        $this->assertSame([], $report->ofType(FabricationFindingType::UnknownEmployer));
    }

    public function test_an_organisation_the_profile_mentions_outside_its_employers_is_not_flagged(): void
    {
        // The university is in `education`, not in `experience`. It is still the
        // candidate's own history, so naming it is not a fabrication.
        $tailored = $this->tailoredContent([[
            'bullets' => ['Taught the systems lab at University of London'],
        ]]);

        $report = $this->guard->inspect($tailored, $this->profile());

        $this->assertTrue($report->isClean(), $report->reviewReason());
    }

    public function test_a_generated_fact_that_the_profile_does_not_contain_is_flagged_as_a_code_defect(): void
    {
        // The merge is the only thing that can produce this: the model has no
        // field for an employer. Asserting it anyway is the point of the check.
        $tailored = $this->tailoredContent([[
            'company' => 'Umbrella Systems',
            'bullets' => ['Owned the billing service'],
        ]]);

        $report = $this->guard->inspect($tailored, $this->profile());

        $findings = $report->ofType(FabricationFindingType::UnknownEmployer);
        $this->assertNotSame([], $findings);
        $this->assertSame('Umbrella Systems', $findings[0]->token);
        $this->assertSame('role 1 (company field)', $findings[0]->location);
        $this->assertStringContainsString('merge defect', $findings[0]->detail);
    }

    /* ------------------------------------------------------------------
     | Name normalisation (task 11.8)
     |
     | The guard's whole accuracy rests on `normalize()`: the profile and the
     | bullet are written by different authors — a resume parser and a model — and
     | never agree on case, punctuation or quote characters. Each of the cases
     | below is a spelling difference that would turn a correct bullet into a
     | review item if the folding were done with, say, a naive `[^a-z0-9]` scrub.
     | ----------------------------------------------------------------- */

    public function test_case_and_trailing_punctuation_do_not_make_a_known_employer_unknown(): void
    {
        // Shouty and dotted, which is how an employer arrives from a resume
        // header far more often than in title case.
        $tailored = $this->tailoredContent([[
            'bullets' => ['Rebuilt the ledger at ANALYTICAL & CO.'],
        ]]);

        $report = $this->guard->inspect($tailored, $this->profile());

        $this->assertTrue($report->isClean(), $report->reviewReason());
    }

    public function test_a_curly_apostrophe_matches_the_profiles_straight_one(): void
    {
        // A model writing prose emits typographic quotes; the profile, typed or
        // parsed from a PDF, holds the ASCII one. Same company.
        $profile = $this->profile([
            'experience' => [[
                'company' => "O'Reilly Media",
                'position' => 'Staff Engineer',
                'duration' => '2022 - 2024',
                'achievements' => ['Owned the docs platform'],
            ]],
        ]);

        $tailored = $this->tailoredContent([[
            'company' => "O'Reilly Media",
            'bullets' => ['Shipped the docs platform at O’Reilly Media'],
        ]]);

        $report = $this->guard->inspect($tailored, $profile);

        $this->assertTrue($report->isClean(), $report->reviewReason());
    }

    public function test_accented_employer_names_survive_normalisation_intact(): void
    {
        // Two halves of one property, because either alone is misleading: the
        // matcher must recognise an accented name the profile lists, *and* still
        // flag an accented name it does not — a normaliser that stripped
        // non-ASCII would fold "Zürich" to "z rich" on both sides and quietly
        // pass either way.
        $profile = $this->profile([
            'experience' => [[
                'company' => 'Zürich Beteiligungen',
                'position' => 'Staff Engineer',
                'duration' => '2022 - 2024',
                'achievements' => ['Owned the billing service'],
            ]],
        ]);

        $known = $this->tailoredContent([[
            'company' => 'Zürich Beteiligungen',
            'bullets' => ['Led the payments rollout at Zürich Beteiligungen'],
        ]]);

        $this->assertTrue(
            $this->guard->inspect($known, $profile)->isClean(),
            $this->guard->inspect($known, $profile)->reviewReason(),
        );

        $invented = $this->tailoredContent([[
            'company' => 'Zürich Beteiligungen',
            'bullets' => ['Led the payments rollout at Genève Ventures'],
        ]]);

        $findings = $this->guard->inspect($invented, $profile)
            ->ofType(FabricationFindingType::UnknownEmployer);

        $this->assertCount(1, $findings);
        // The token is quoted back to a reviewer, so the accent has to survive
        // the round trip through the matcher as well as the match itself.
        $this->assertSame('Genève Ventures', $findings[0]->token);
    }

    public function test_an_organisational_suffix_the_profile_omits_is_not_a_mismatch(): void
    {
        // The profile says "Globex"; the bullet says "Globex Inc". This is the
        // suffix-stripping path, and it is the difference between a guard people
        // trust and one that flags most of its own employers: profiles record
        // trading names, prose adds the legal suffix.
        $profile = $this->profile([
            'experience' => [[
                'company' => 'Globex',
                'position' => 'Staff Engineer',
                'duration' => '2022 - 2024',
                'achievements' => ['Owned the billing service'],
            ]],
        ]);

        $tailored = $this->tailoredContent([[
            'company' => 'Globex',
            'bullets' => ['Owned the migration at Globex Inc'],
        ]]);

        $report = $this->guard->inspect($tailored, $profile);

        $this->assertTrue($report->isClean(), $report->reviewReason());
    }

    /* ------------------------------------------------------------------
     | Profiles that are not the shape the guard would like (task 11.8)
     | ----------------------------------------------------------------- */

    public function test_an_experience_entry_that_is_not_an_array_is_skipped(): void
    {
        // `user_profiles.experience` is a JSON column, so its contents are
        // whatever a client last posted. A string where an object belongs must not
        // take the guard down mid-pipeline — the run it would fail is the one
        // carrying a resume somebody is waiting on — and it must not make the
        // surviving entries unverifiable either.
        $profile = $this->profile([
            'experience' => [
                'Staff Engineer at Analytical & Co',
                ['company' => 'Analytical & Co', 'position' => 'Staff Engineer', 'duration' => '2022 - 2024'],
                null,
            ],
        ]);

        $tailored = $this->tailoredContent([[
            'bullets' => ['Owned the billing service through a rewrite in 2023'],
        ]]);

        $report = $this->guard->inspect($tailored, $profile, $this->texFor($tailored));

        $this->assertTrue($report->isClean(), $report->reviewReason());
        $this->assertTrue($report->datesVerifiable);
    }

    public function test_a_profile_with_no_experience_at_all_flags_every_merged_fact(): void
    {
        // Nothing supports the document, so nothing in it is verifiable — and the
        // honest report is two findings, not a pass. A guard that treated an empty
        // vocabulary as "nothing to contradict" would hand a clean bill of health
        // to exactly the profile least able to earn one.
        $profile = $this->profile(['experience' => [], 'education' => []]);

        $tailored = $this->tailoredContent([[
            'company' => 'Analytical & Co',
            'position' => 'Staff Engineer',
            'bullets' => ['Owned the billing service'],
        ]]);

        $report = $this->guard->inspect($tailored, $profile);

        $this->assertTrue($report->requiresReview());

        $findings = $report->ofType(FabricationFindingType::UnknownEmployer);
        $this->assertSame(['Analytical & Co', 'Staff Engineer'], array_column($findings, 'token'));
        $this->assertSame(
            ['role 1 (company field)', 'role 1 (position field)'],
            array_column($findings, 'location'),
        );

        foreach ($findings as $finding) {
            $this->assertStringContainsString('merge defect', $finding->detail);
        }
    }

    /* ------------------------------------------------------------------
     | Dates
     | ----------------------------------------------------------------- */

    public function test_a_year_inside_the_roles_span_is_not_flagged(): void
    {
        $tailored = $this->tailoredContent([[
            'dates' => '2022 - 2024',
            'bullets' => ['Grew revenue 40% in 2023'],
        ]]);

        $report = $this->guard->inspect($tailored, $this->profile());

        $this->assertTrue($report->isClean(), $report->reviewReason());
        $this->assertTrue($report->datesVerifiable);
    }

    public function test_a_year_one_off_the_stated_boundary_is_within_tolerance(): void
    {
        // The profile says 2022-2024; a bullet about work in 2021 is a rounding
        // artefact of a coarse date field, not a different claim.
        $tailored = $this->tailoredContent([[
            'dates' => '2022 - 2024',
            'bullets' => ['Started the rewrite in 2021 and shipped it the year after'],
        ]]);

        $report = $this->guard->inspect($tailored, $this->profile());

        $this->assertTrue($report->isClean(), $report->reviewReason());
    }

    public function test_a_year_the_profile_does_not_support_is_flagged(): void
    {
        $tailored = $this->tailoredContent([[
            'dates' => '2022 - 2024',
            'bullets' => ['Has led distributed systems teams since 2005'],
        ]]);

        $report = $this->guard->inspect($tailored, $this->profile());

        $findings = $report->ofType(FabricationFindingType::UnsupportedDate);
        $this->assertCount(1, $findings);
        $this->assertSame('2005', $findings[0]->token);
        $this->assertStringContainsString('bullet 1 of role 1', $findings[0]->location);
    }

    public function test_a_range_outside_the_supported_span_is_flagged_per_year(): void
    {
        $tailored = $this->tailoredContent([[
            'dates' => '2022 - 2024',
            'bullets' => ['Ran the data platform 2014-2016'],
        ]]);

        $report = $this->guard->inspect($tailored, $this->profile());

        $tokens = array_map(
            fn ($finding): string => $finding->token,
            $report->ofType(FabricationFindingType::UnsupportedDate),
        );

        $this->assertSame(['2014', '2016'], $tokens);
    }

    public function test_a_profile_whose_dates_pin_no_year_disables_the_date_check(): void
    {
        // The parser writes `duration` as a month count, so the document's date
        // reads "2 yr 6 mo" and supports no calendar year at all. The honest
        // answer is "not checked", not "no problems found".
        $profile = $this->profile([
            'experience' => [[
                'company' => 'Analytical & Co',
                'position' => 'Staff Engineer',
                'duration' => 30,
                'achievements' => ['Owned the billing service'],
            ]],
            'education' => [],
        ]);

        $tailored = $this->tailoredContent([[
            'dates' => '2 yr 6 mo',
            'bullets' => ['Led the rewrite in 1993'],
        ]]);

        $report = $this->guard->inspect($tailored, $profile);

        $this->assertFalse($report->datesVerifiable);
        $this->assertSame([], $report->ofType(FabricationFindingType::UnsupportedDate));
        $this->assertFalse($report->flags()['dates_verifiable']);
    }

    /* ------------------------------------------------------------------
     | The rendered document
     | ----------------------------------------------------------------- */

    public function test_latex_escaping_does_not_make_a_present_fact_look_missing(): void
    {
        // `Analytical & Co` reaches the source as `Analytical \& Co`, and a naive
        // search for the raw value would report a missing employer here.
        $tailored = $this->tailoredContent([[
            'company' => 'Analytical & Co',
            'dates' => '2022 - 2024',
            'bullets' => ['Owned the billing service'],
        ]]);

        $tex = $this->texFor($tailored);

        $this->assertStringContainsString('Analytical \& Co', $tex);
        $this->assertStringNotContainsString('Analytical & Co', $tex);

        $report = $this->guard->inspect($tailored, $this->profile(), $tex);

        $this->assertSame([], $report->ofType(FabricationFindingType::MissingFact));
        $this->assertTrue($report->isClean(), $report->reviewReason());
    }

    public function test_a_fact_dropped_by_the_template_is_reported_as_a_missing_fact(): void
    {
        $tailored = $this->tailoredContent([[
            'company' => 'Analytical & Co',
            'dates' => '2022 - 2024',
            'bullets' => ['Owned the billing service'],
        ]]);

        // A template revision that stopped echoing the employer.
        $tex = str_replace(LatexEscaper::escape('Analytical & Co'), '', $this->texFor($tailored));

        $report = $this->guard->inspect($tailored, $this->profile(), $tex);

        $findings = $report->ofType(FabricationFindingType::MissingFact);
        $this->assertCount(1, $findings);
        $this->assertSame('Analytical & Co', $findings[0]->token);
        $this->assertSame('rendered document (role 1 company)', $findings[0]->location);

        // This one will not fix itself on a re-queue, and the review item says so.
        $this->assertTrue($findings[0]->type->indicatesCodeDefect());
    }

    public function test_without_a_rendered_document_the_missing_fact_check_is_reported_as_not_run(): void
    {
        $tailored = $this->tailoredContent([['bullets' => ['Owned the billing service']]]);

        $report = $this->guard->inspect($tailored, $this->profile());

        $this->assertFalse($report->texScanned);
        $this->assertSame([], $report->ofType(FabricationFindingType::MissingFact));
        $this->assertFalse($report->flags()['tex_scanned']);
    }

    /* ------------------------------------------------------------------
     | The report as the caller of task 11.7 will use it
     | ----------------------------------------------------------------- */

    public function test_the_report_serialises_to_the_flags_a_caller_persists(): void
    {
        $tailored = $this->tailoredContent([[
            'dates' => '2022 - 2024',
            'bullets' => ['Led the platform migration for Globex Inc since 2005'],
        ]]);

        $report = $this->guard->inspect($tailored, $this->profile(), $this->texFor($tailored));
        $flags = $report->flags();

        $this->assertFalse($flags['clean']);
        $this->assertCount(2, $flags['findings']);
        $this->assertSame(
            ['unknown_employer', 'unsupported_date'],
            array_column($flags['findings'], 'type'),
        );

        foreach ($flags['findings'] as $finding) {
            $this->assertSame(['type', 'token', 'location', 'detail'], array_keys($finding));
            $this->assertNotSame('', $finding['detail']);
        }

        // Survives the json round-trip it is stored through.
        $this->assertSame($flags, json_decode((string) json_encode($flags), true));

        $this->assertSame(1, $report->context()['fabrication_counts']['unknown_employer']);
        $this->assertSame(2, $report->context()['fabrication_findings']);
    }

    public function test_findings_are_bounded_by_configuration(): void
    {
        config(['pipeline.fabrication.max_findings' => 2]);

        $tailored = $this->tailoredContent([[
            'bullets' => [
                'Worked at Globex Inc',
                'Worked at Initech Holdings',
                'Worked at Umbrella Ventures',
            ],
        ]]);

        $report = $this->guard->inspect($tailored, $this->profile());

        $this->assertCount(2, $report->findings);
        $this->assertSame(1, $report->truncated);
        $this->assertTrue($report->requiresReview());
    }
}

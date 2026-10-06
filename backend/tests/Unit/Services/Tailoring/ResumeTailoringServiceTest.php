<?php

namespace Tests\Unit\Services\Tailoring;

use App\Exceptions\ModelRouterException;
use App\Exceptions\ResumeTailoringException;
use App\Models\JobListing;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\ModelCompletionResult;
use App\Services\ModelRouterService;
use App\Services\ModelTierContext;
use App\Services\Tailoring\ResumeTailoringService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Tests\TestCase;
use Throwable;

/**
 * The tailoring step of Requirement 6.1 (task 11.3): a routed, schema-constrained
 * model call whose output is merged *over* the profile's factual data.
 *
 * The router is replaced by a recording fake rather than faked at the HTTP layer,
 * for the same reason {@see \Tests\Unit\Services\Scoring\JobScoringServiceTest}
 * does it: what matters here is the service's own behaviour — which task type
 * and schema it asks for, what it refuses to accept, and what it does with an
 * unusable response — none of which should depend on OpenRouter's wire format.
 *
 * Nothing is persisted (the service never touches the database) and no TeX
 * engine is involved: the one test that goes as far as the template *renders*
 * it and stops, since compiling would need tectonic installed.
 */
class ResumeTailoringServiceTest extends TestCase
{
    private FakeTailoringRouter $router;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'latex.tailoring.max_attempts' => 2,
            'latex.tailoring.max_description_chars' => 12000,
            'latex.tailoring.max_profile_chars' => 12000,
            'latex.tailoring.max_experience_entries' => 6,
            'latex.tailoring.max_bullets_per_role' => 4,
            'latex.tailoring.max_bullet_chars' => 300,
            'latex.tailoring.max_summary_chars' => 700,
            'latex.tailoring.max_headline_chars' => 120,
            'latex.tailoring.max_skills_per_group' => 18,
            'latex.tailoring.max_emphasized_skills' => 15,
            'latex.tailoring.skill_group_labels' => [
                'primary' => 'Core Skills',
                'secondary' => 'Additional Skills',
            ],
            'latex.tailoring.default_skill_group_label' => 'Skills',
            'latex.tailoring.default_template_key' => 'default',
        ]);

        // The failure and discard paths log deliberately.
        Log::spy();

        $this->router = new FakeTailoringRouter;
    }

    private function service(): ResumeTailoringService
    {
        return new ResumeTailoringService($this->router);
    }

    private function listing(array $overrides = []): JobListing
    {
        return new JobListing(array_merge([
            'title' => 'Senior Backend Engineer',
            'company' => 'Acme Corp',
            'location' => 'Remote',
            'description' => str_repeat('We need Laravel, PostgreSQL and Kubernetes experience. ', 20),
            'application_url' => 'https://jobs.example.com/1',
            'salary_min' => 120000,
            'salary_max' => 160000,
            'currency' => 'USD',
        ], $overrides));
    }

    /**
     * A profile with the exact shapes the resume parser and
     * `Api\ProfileController` write: skills grouped primary/secondary,
     * experience with a month count, education with parallel year fields.
     */
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
                    'company' => 'Analytical & Co',
                    'position' => 'Staff Engineer',
                    'duration' => 30,
                    'achievements' => ['Owned the billing service', 'Mentored four engineers'],
                ],
                [
                    'company' => 'Globex',
                    'position' => 'Backend Engineer',
                    'duration' => 18,
                    'achievements' => ['Built the reporting API'],
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

    /**
     * A well-formed model response: summary, headline, emphasis and bullets
     * bound to roles by index.
     */
    private function tailoringJson(array $overrides = []): string
    {
        return (string) json_encode(array_merge([
            'headline' => 'Senior Backend Engineer',
            'summary' => 'Backend engineer with six years building Laravel services on PostgreSQL.',
            'emphasizedSkills' => ['PostgreSQL', 'Laravel'],
            'experience' => [
                [
                    'index' => 0,
                    'bullets' => [
                        'Owned a billing service handling 40% of company revenue',
                        'Mentored four engineers to independent delivery',
                    ],
                ],
                [
                    'index' => 1,
                    'bullets' => ['Built a reporting API used across the business'],
                ],
            ],
        ], $overrides));
    }

    /* ------------------------------------------------------------------
     | Happy path
     | ----------------------------------------------------------------- */

    public function test_it_returns_the_template_contract_with_tailored_wording(): void
    {
        $this->router->willReturn($this->tailoringJson());

        $tailored = $this->service()->tailor($this->listing(), $this->profile());
        $content = $tailored->forTemplate();

        $this->assertSame('Ada Lovelace', $content['name']);
        $this->assertSame('Senior Backend Engineer', $content['headline']);
        $this->assertStringContainsString('six years building Laravel', $content['summary']);

        // Contact header is assembled from the profile, not the model.
        $this->assertSame(
            ['ada@example.com', 'Austin, TX, US', 'https://linkedin.com/in/ada'],
            $content['contact']
        );

        // Bullets are the model's wording, in the order it gave them.
        $this->assertSame(
            [
                'Owned a billing service handling 40% of company revenue',
                'Mentored four engineers to independent delivery',
            ],
            $content['experience'][0]['bullets']
        );

        $this->assertSame(1, $tailored->attempts);
        $this->assertSame('vendor/standard-1', $tailored->modelUsed);
        $this->assertSame($this->tailoringJson(), $tailored->rawOutput);
    }

    public function test_employers_titles_and_dates_come_from_the_profile_verbatim(): void
    {
        $this->router->willReturn($this->tailoringJson());

        $tailored = $this->service()->tailor($this->listing(), $this->profile());
        $experience = $tailored->forTemplate()['experience'];

        $this->assertCount(2, $experience);

        // The factual skeleton, exactly as `user_profiles.experience` holds it —
        // 30 and 18 months rendered as ranges, nothing reworded.
        $this->assertSame('Analytical & Co', $experience[0]['company']);
        $this->assertSame('Staff Engineer', $experience[0]['position']);
        $this->assertSame('2 yr 6 mo', $experience[0]['dates']);
        $this->assertSame('Globex', $experience[1]['company']);
        $this->assertSame('Backend Engineer', $experience[1]['position']);
        $this->assertSame('1 yr 6 mo', $experience[1]['dates']);

        // Education likewise, including the profile's `fieldOfStudy` becoming the
        // template's `field`.
        $this->assertSame([[
            'institution' => 'University of London',
            'degree' => 'BSc',
            'field' => 'Mathematics',
            'location' => '',
            'dates' => '2009 - 2012',
        ]], $tailored->forTemplate()['education']);

        // The same tokens are carried separately for the fabrication guard of
        // task 11.5 to diff the rendered document against.
        $this->assertSame([
            ['company' => 'Analytical & Co', 'position' => 'Staff Engineer', 'dates' => '2 yr 6 mo'],
            ['company' => 'Globex', 'position' => 'Backend Engineer', 'dates' => '1 yr 6 mo'],
        ], $tailored->facts);
    }

    public function test_a_model_that_restates_facts_cannot_change_them(): void
    {
        // A model ignoring the contract and volunteering an employer, a title and
        // a date range alongside its bullets.
        $this->router->willReturn($this->tailoringJson([
            'experience' => [[
                'index' => 0,
                'company' => 'Fabricated Inc',
                'position' => 'Chief Architect',
                'dates' => '2001 - Present',
                'bullets' => ['Owned the billing service'],
            ]],
        ]));

        $experience = $this->service()->tailor($this->listing(), $this->profile())->forTemplate()['experience'];

        $this->assertSame('Analytical & Co', $experience[0]['company']);
        $this->assertSame('Staff Engineer', $experience[0]['position']);
        $this->assertSame('2 yr 6 mo', $experience[0]['dates']);

        $encoded = (string) json_encode($experience);
        $this->assertStringNotContainsString('Fabricated Inc', $encoded);
        $this->assertStringNotContainsString('Chief Architect', $encoded);
    }

    public function test_it_asks_for_the_resume_tailor_task_type_with_a_schema_that_has_no_factual_fields(): void
    {
        $this->router->willReturn($this->tailoringJson());
        $listing = $this->listing();

        $this->service()->tailor($listing, $this->profile());

        $call = $this->router->calls[0];
        $this->assertSame(ResumeTailoringService::TASK_RESUME_TAILOR, $call['taskType']);
        $this->assertSame('resume_tailor', $call['taskType']);
        // Token spend is attributed to the listing being tailored for (Req 4.6).
        $this->assertSame($listing, $call['relatedTo']);

        $this->assertSame(
            ['headline', 'summary', 'emphasizedSkills', 'experience'],
            $call['jsonSchema']['required']
        );
        // The structural guarantee: there is no field for an employer, a title or
        // a date, so there is nothing for the model to fabricate.
        $roleProperties = array_keys($call['jsonSchema']['properties']['experience']['items']['properties']);
        $this->assertSame(['index', 'bullets'], $roleProperties);

        // Salary decides the tier for this listing (Req 4.3).
        $this->assertTrue($call['context']->hasSalary());
        $this->assertSame(160000, $call['context']->salaryBasis());
    }

    public function test_the_prompt_carries_the_posting_and_indexed_achievements(): void
    {
        $this->router->willReturn($this->tailoringJson());

        $this->service()->tailor($this->listing(), $this->profile());

        $user = $this->router->calls[0]['messages'][1]['content'];
        $this->assertStringContainsString('Kubernetes experience', $user);
        $this->assertStringContainsString('"index": 0', $user);
        $this->assertStringContainsString('Owned the billing service', $user);
        // The header is not the model's business, so its contents are not sent.
        $this->assertStringNotContainsString('ada@example.com', $user);
    }

    /* ------------------------------------------------------------------
     | Skill emphasis is an ordering, never a membership change
     | ----------------------------------------------------------------- */

    public function test_emphasized_skills_are_reordered_and_invented_ones_dropped(): void
    {
        $this->router->willReturn($this->tailoringJson([
            'emphasizedSkills' => ['postgresql', 'Kubernetes', 'Docker'],
        ]));

        $tailored = $this->service()->tailor($this->listing(), $this->profile());

        $this->assertSame([
            // PostgreSQL matched case-insensitively and moved to the front, in the
            // profile's own spelling.
            ['label' => 'Core Skills', 'items' => ['PostgreSQL', 'Laravel', 'PHP']],
            ['label' => 'Additional Skills', 'items' => ['Docker', 'Redis']],
        ], $tailored->forTemplate()['skills']);

        // Kubernetes is not on this profile, so it is reported rather than added.
        $this->assertSame(['Kubernetes'], $tailored->droppedSkills);
    }

    /* ------------------------------------------------------------------
     | The model cannot inject LaTeX
     | ----------------------------------------------------------------- */

    public function test_latex_in_the_response_is_rejected_and_retried_as_plain_text(): void
    {
        $this->router->willReturn($this->tailoringJson([
            'experience' => [[
                'index' => 0,
                'bullets' => ['Owned billing \\input{/etc/passwd} and \\textbf{scaled} it'],
            ]],
        ]));
        $this->router->willReturn($this->tailoringJson());

        $tailored = $this->service()->tailor($this->listing(), $this->profile());

        $this->assertSame(2, $tailored->attempts);
        $this->assertStringNotContainsString('\\input', (string) json_encode($tailored->forTemplate()));
        $this->assertStringNotContainsString('\\textbf', (string) json_encode($tailored->forTemplate()));

        // The retry says what was wrong, in terms the model can act on.
        $retry = $this->router->calls[1]['messages'][0]['content'];
        $this->assertStringContainsString('contains LaTeX markup', $retry);
        $this->assertStringContainsString('Return ONLY valid JSON matching this schema', $retry);
    }

    public function test_latex_in_the_summary_is_rejected_too(): void
    {
        $this->router->willReturn($this->tailoringJson([
            'summary' => 'Backend engineer. \\vspace{5cm} Available immediately.',
        ]));
        $this->router->willReturn($this->tailoringJson());

        $tailored = $this->service()->tailor($this->listing(), $this->profile());

        $this->assertSame(2, $tailored->attempts);
        $this->assertStringNotContainsString('vspace', $tailored->forTemplate()['summary']);
    }

    public function test_prose_punctuation_the_template_escapes_is_not_mistaken_for_latex(): void
    {
        // Every one of these is legitimate resume prose and every one is a LaTeX
        // special character. The template escapes them; rejecting them here would
        // throw away good bullets.
        $this->router->willReturn($this->tailoringJson([
            'summary' => 'Grew R&D throughput 40% on a $2M budget; owned cost_per_unit and ~99.9% uptime.',
        ]));

        $tailored = $this->service()->tailor($this->listing(), $this->profile());

        $this->assertSame(1, $tailored->attempts);
        $this->assertStringContainsString('R&D throughput 40%', $tailored->forTemplate()['summary']);
    }

    /* ------------------------------------------------------------------
     | One stricter retry, then a typed failure
     | ----------------------------------------------------------------- */

    public function test_a_malformed_payload_earns_one_retry_with_a_stricter_instruction(): void
    {
        $this->router->willReturn($this->tailoringJson(['summary' => '   ']));
        $this->router->willReturn($this->tailoringJson());

        $tailored = $this->service()->tailor($this->listing(), $this->profile());

        $this->assertSame(2, $tailored->attempts);
        $this->assertCount(2, $this->router->calls);

        $first = $this->router->calls[0]['messages'][0]['content'];
        $retry = $this->router->calls[1]['messages'][0]['content'];
        $this->assertStringNotContainsString('ONLY valid JSON', $first);
        $this->assertStringContainsString('Return ONLY valid JSON matching this schema', $retry);
        // The schema is restated inline, for a model whose provider dropped
        // `response_format`.
        $this->assertStringContainsString('"emphasizedSkills"', $retry);
        $this->assertStringContainsString('"bullets"', $retry);
    }

    public function test_an_unparseable_router_response_earns_the_same_retry(): void
    {
        $this->router->willThrow(ModelRouterException::unparseableJson(
            'resume_tailor',
            'standard',
            'vendor/standard-1',
            'Sure! Here is a tailored resume: ...'
        ));
        $this->router->willReturn($this->tailoringJson());

        $tailored = $this->service()->tailor($this->listing(), $this->profile());

        $this->assertSame(2, $tailored->attempts);
    }

    public function test_two_failures_raise_a_typed_exception_carrying_the_attempt_history(): void
    {
        $this->router->willReturn($this->tailoringJson(['summary' => '']));
        $this->router->willReturn($this->tailoringJson([
            'summary' => 'Fine, but \\LaTeX{} is not.',
        ]));

        try {
            $this->service()->tailor($this->listing(), $this->profile());
            $this->fail('Expected a ResumeTailoringException once both attempts failed.');
        } catch (ResumeTailoringException $e) {
            $this->assertCount(2, $e->attempts);
            $this->assertSame('resume_tailor', $e->context['task_type']);
            // Both failures were about content, which is what tells the caller
            // this is a model that will not follow the contract rather than an
            // outage.
            $this->assertTrue($e->failedOnParsing());
            $this->assertStringContainsString('missing a usable `summary`', $e->attempts[0]['reason']);
            $this->assertStringContainsString('contains LaTeX markup', $e->attempts[1]['reason']);
            $this->assertNotSame('', $e->reviewReason());
        }

        $this->assertCount(2, $this->router->calls);
    }

    public function test_the_retry_allowance_is_configurable_and_never_exceeded(): void
    {
        config(['latex.tailoring.max_attempts' => 1]);

        $this->router->willReturn($this->tailoringJson(['summary' => '']));
        $this->router->willReturn($this->tailoringJson());

        $this->expectException(ResumeTailoringException::class);

        try {
            $this->service()->tailor($this->listing(), $this->profile());
        } finally {
            // One attempt only: the second queued response was never asked for.
            $this->assertCount(1, $this->router->calls);
        }
    }

    public function test_bullets_for_no_real_role_are_treated_as_unusable(): void
    {
        // Every index is out of range, so nothing the model wrote can be attached
        // to a job the candidate actually held.
        $this->router->willReturn($this->tailoringJson([
            'experience' => [['index' => 9, 'bullets' => ['Led the moon landing']]],
        ]));
        $this->router->willReturn($this->tailoringJson());

        $tailored = $this->service()->tailor($this->listing(), $this->profile());

        $this->assertSame(2, $tailored->attempts);
        $this->assertStringNotContainsString(
            'moon landing',
            (string) json_encode($tailored->forTemplate())
        );
    }

    /* ------------------------------------------------------------------
     | Nothing to tailor: no model call at all
     | ----------------------------------------------------------------- */

    public function test_a_listing_with_no_description_fails_without_calling_a_model(): void
    {
        try {
            $this->service()->tailor($this->listing(['description' => '  ']), $this->profile());
            $this->fail('Expected a ResumeTailoringException for an untailorable listing.');
        } catch (ResumeTailoringException $e) {
            $this->assertStringContainsString('needs enrichment', $e->getMessage());
        }

        $this->assertSame([], $this->router->calls);
    }

    public function test_an_empty_profile_fails_without_calling_a_model(): void
    {
        $empty = $this->profile(['skills' => [], 'experience' => [], 'education' => []]);

        try {
            $this->service()->tailor($this->listing(), $empty);
            $this->fail('Expected a ResumeTailoringException for an empty profile.');
        } catch (ResumeTailoringException $e) {
            $this->assertStringContainsString('needs parsing first', $e->getMessage());
        }

        $this->assertSame([], $this->router->calls);
    }

    /* ------------------------------------------------------------------
     | Template selection (task 11.8)
     | ----------------------------------------------------------------- */

    public function test_the_default_template_key_is_read_from_configuration(): void
    {
        // Requirement 6.3 lets a caller pick a template and this is the value used
        // when it does not. It is env-driven (`TAILORING_TEMPLATE_KEY`), so it is
        // read per call rather than captured at construction — a queue worker
        // booted before a config change would otherwise keep the old key for its
        // whole life.
        config(['latex.tailoring.default_template_key' => 'compact']);

        $this->assertSame('compact', $this->service()->defaultTemplateKey());
    }

    public function test_the_configured_key_is_trimmed_and_a_useless_value_falls_back(): void
    {
        $service = $this->service();

        config(['latex.tailoring.default_template_key' => "  compact\n"]);
        $this->assertSame('compact', $service->defaultTemplateKey());

        // Every shape an env var or a hand-edited config can be empty in. Each
        // yields the shipped default rather than an empty key, which the render
        // service would reject outright and take the whole run down with it — an
        // unset variable should not stop a resume being written.
        foreach ([null, '', '   ', 0, [], ['default']] as $useless) {
            config(['latex.tailoring.default_template_key' => $useless]);

            $this->assertSame(
                'default',
                $service->defaultTemplateKey(),
                sprintf('config value %s should have fallen back to the shipped default', json_encode($useless)),
            );
        }
    }

    /* ------------------------------------------------------------------
     | Output budgets
     | ----------------------------------------------------------------- */

    public function test_bullet_counts_and_lengths_are_bounded_by_config(): void
    {
        config([
            'latex.tailoring.max_bullets_per_role' => 2,
            'latex.tailoring.max_bullet_chars' => 40,
        ]);

        $this->router->willReturn($this->tailoringJson([
            'experience' => [[
                'index' => 0,
                'bullets' => [
                    str_repeat('Scaled the platform considerably. ', 5),
                    'Second bullet',
                    'Third bullet',
                    'Fourth bullet',
                ],
            ]],
        ]));

        $bullets = $this->service()->tailor($this->listing(), $this->profile())
            ->forTemplate()['experience'][0]['bullets'];

        $this->assertCount(2, $bullets);
        $this->assertLessThanOrEqual(40, mb_strlen($bullets[0]));
    }

    public function test_a_role_the_model_skipped_keeps_its_own_stored_achievements(): void
    {
        $this->router->willReturn($this->tailoringJson([
            'experience' => [['index' => 0, 'bullets' => ['Owned billing end to end']]],
        ]));

        $experience = $this->service()->tailor($this->listing(), $this->profile())
            ->forTemplate()['experience'];

        // Untailored but true: the candidate's own words about a job they held,
        // rather than a heading with nothing under it.
        $this->assertSame(['Built the reporting API'], $experience[1]['bullets']);
    }

    /* ------------------------------------------------------------------
     | The template accepts the output
     | ----------------------------------------------------------------- */

    public function test_the_output_renders_a_complete_latex_document(): void
    {
        $this->router->willReturn($this->tailoringJson());

        $content = $this->service()->tailor($this->listing(), $this->profile())->forTemplate();

        // Rendered only, never compiled: tectonic is not installed in CI, and what
        // is under test is that the content array satisfies the template's
        // contract — every key it reads is present and the right shape.
        $tex = View::make('latex::resume', $content)->render();

        $this->assertStringContainsString('\documentclass', $tex);
        $this->assertStringContainsString('\begin{document}', $tex);
        $this->assertStringContainsString('\end{document}', $tex);

        $this->assertStringContainsString('Ada Lovelace', $tex);
        $this->assertStringContainsString('Staff Engineer', $tex);
        $this->assertStringContainsString('University of London', $tex);
        $this->assertStringContainsString('Core Skills', $tex);

        // Requirement 6.3 end to end: the ampersand in a real employer name and
        // the percent sign in a model-written bullet both arrive escaped.
        $this->assertStringContainsString('Analytical \& Co', $tex);
        $this->assertStringContainsString('40\%', $tex);
    }
}

/**
 * Records what the tailoring service asked for and returns queued responses.
 *
 * A subclass rather than a mock so the type contract of
 * {@see ModelRouterService::complete()} is enforced by PHP: if the real
 * signature changes, this fake stops compiling instead of silently drifting.
 */
class FakeTailoringRouter extends ModelRouterService
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
            inputTokens: 1400,
            outputTokens: 260,
            estimatedCostUsd: 0.0039,
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
            throw new \LogicException('FakeTailoringRouter received an unexpected call for task '.$taskType);
        }

        $next = array_shift($this->queue);

        if ($next instanceof Throwable) {
            throw $next;
        }

        return $next;
    }
}

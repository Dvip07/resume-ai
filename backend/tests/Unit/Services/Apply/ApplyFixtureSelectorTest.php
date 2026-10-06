<?php

namespace Tests\Unit\Services\Apply;

use App\Models\JobListing;
use App\Models\User;
use App\Models\UserAutomationSetting;
use App\Models\UserProfile;
use App\Services\Apply\Adapters\GreenhouseApplyAdapter;
use App\Services\Apply\Adapters\LeverApplyAdapter;
use App\Services\Apply\Adapters\LinkedInEasyApplyAdapter;
use App\Services\Apply\Adapters\WorkdayApplyAdapter;
use App\Services\Apply\ApplyAdapter;
use App\Services\Apply\ApplyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every ATS adapter's selectors, against recorded fixture markup (task 15.11).
 *
 * The sibling adapter tests fake the automation worker and assert the compiled
 * step script. That proves the adapter asks for the right *steps* — and proves
 * nothing at all about the selectors those steps carry, because the fake reports
 * `ok` for whatever it was handed. A typo in `config/apply.php` passes all of
 * them and surfaces only in production, as a timeout against a real form.
 *
 * So this file resolves the selectors themselves against hand-authored
 * representations of each ATS's markup (`tests/Fixtures/Apply`, see the README
 * there — nothing is fetched, in CI or anywhere else). Three things are checked
 * per adapter:
 *
 * 1. every configured selector resolves in the fixture for the page it is used
 *    on — exactly one element for a field/upload/click target, at least one for
 *    a page-scope selector;
 * 2. every selector the adapter actually compiles into a script is one of those
 *    checked selectors, so a selector cannot be added to an adapter and skip
 *    the fixture;
 * 3. a wall fixture (CAPTCHA or sign-in) ends the run in `needs_review` rather
 *    than a retry (Req 9.6).
 *
 * The LinkedIn opt-in is NOT re-tested here: `LinkedInEasyApplyAdapterTest`
 * already covers it at both levels — the adapter sends no HTTP at all without
 * consent (`test_without_the_opt_in_nothing_is_driven`,
 * `test_a_user_with_no_settings_row_has_not_opted_in`) and the registry never
 * resolves it without consent (`test_the_registry_never_resolves_linkedin_without_the_opt_in`),
 * which is the requirement. Duplicating it would add a second place to maintain
 * and no coverage.
 *
 * Validates: Requirements 9.2, 9.3, 9.6
 */
class ApplyFixtureSelectorTest extends TestCase
{
    use FakesApplyWorker, RefreshDatabase, ResolvesFixtureSelectors;

    private const WORKER = 'http://127.0.0.1:8081/apply';

    private const RESUME_KEY = 'users/7/tailored-documents/11/resume.pdf';

    private const COVER_LETTER_KEY = 'users/7/tailored-documents/12/cover-letter.pdf';

    /** Answers for every question any adapter configures, so no run pauses short of submit. */
    private const ANSWERS = [
        'work_authorization' => 'Yes',
        'visa_sponsorship' => 'No',
        'current_location' => 'London, UK',
        'how_did_you_hear' => 'LinkedIn',
    ];

    private User $user;

    /**
     * Per-ATS wiring: which adapter, which URL, and which fixture page each
     * configured selector is used on.
     *
     * `exact` is a field, upload or click target — one selector, one element.
     * `any` is a page scope that ends in a broad fallback on purpose (`body`,
     * `.content-wrapper h2`), where more than one match is correct.
     * `unused` names the selectors config deliberately leaves empty, so the
     * coverage check below can tell "empty on purpose" from "forgotten".
     */
    private const ADAPTERS = [
        'greenhouse' => [
            'adapter' => GreenhouseApplyAdapter::class,
            'url' => 'https://boards.greenhouse.io/acme/jobs/4242',
            'confirmed_url' => 'https://boards.greenhouse.io/acme/jobs/4242/confirmation',
            'questions_page' => 'form',
            'exact' => [
                'form' => 'form',
                'first_name' => 'form',
                'last_name' => 'form',
                'email' => 'form',
                'phone' => 'form',
                'resume' => 'form',
                'cover_letter' => 'form',
                'linkedin' => 'form',
                'website' => 'form',
                'submit' => 'form',
            ],
            'any' => [
                'page_text' => 'form',
                'confirmation' => 'confirmation',
            ],
            'unused' => [],
        ],
        'lever' => [
            'adapter' => LeverApplyAdapter::class,
            'url' => 'https://jobs.lever.co/acme/4242-1111-2222',
            'confirmed_url' => 'https://jobs.lever.co/acme/4242-1111-2222/thanks',
            'questions_page' => 'form',
            'exact' => [
                'form' => 'form',
                'name' => 'form',
                'email' => 'form',
                'phone' => 'form',
                'company' => 'form',
                'location' => 'form',
                'resume' => 'form',
                'linkedin' => 'form',
                'github' => 'form',
                'portfolio' => 'form',
                'submit' => 'form',
            ],
            'any' => [
                'page_text' => 'form',
                'confirmation' => 'confirmation',
            ],
            'unused' => [],
        ],
        'workday' => [
            'adapter' => WorkdayApplyAdapter::class,
            'url' => 'https://acme.wd5.myworkdayjobs.com/en-US/careers/job/London/Senior-Engineer_R-4242',
            'confirmed_url' => 'https://acme.wd5.myworkdayjobs.com/en-US/careers/confirmation',
            'questions_page' => 'form',
            'exact' => [
                'apply' => 'posting',
                'first_name' => 'form',
                'last_name' => 'form',
                'email' => 'form',
                'phone' => 'form',
                'resume' => 'form',
                'submit' => 'form',
            ],
            'any' => [
                'posting' => 'posting',
                'page_text' => 'posting',
                // Workday's form selector names three different landmarks on
                // the apply page (first-name field, email field, flow wrapper),
                // any one of which proves the form rendered.
                'form' => 'form',
                'confirmation' => 'confirmation',
            ],
            // No cover-letter input in the My Information flow.
            'unused' => ['cover_letter'],
        ],
        'linkedin' => [
            'adapter' => LinkedInEasyApplyAdapter::class,
            'url' => 'https://www.linkedin.com/jobs/view/3912345678/',
            'confirmed_url' => 'https://www.linkedin.com/jobs/view/3912345678/post-apply/',
            'questions_page' => 'modal',
            'exact' => [
                'easy_apply' => 'posting',
                'modal' => 'modal',
                'first_name' => 'modal',
                'last_name' => 'modal',
                'email' => 'modal',
                'phone' => 'modal',
                'resume' => 'modal',
                'next' => 'modal',
                'submit' => 'modal',
            ],
            'any' => [
                'posting' => 'posting',
                'page_text' => 'posting',
                'confirmation' => 'confirmation',
            ],
            // Easy Apply has no cover-letter input of its own.
            'unused' => ['cover_letter'],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');
        Storage::disk('s3')->put(self::RESUME_KEY, '%PDF-1.4 resume');
        Storage::disk('s3')->put(self::COVER_LETTER_KEY, '%PDF-1.4 cover letter');

        config([
            'services.automation_worker.enabled' => true,
            'services.automation_worker.base_url' => 'http://127.0.0.1:8081',
            'services.automation_worker.apply_path' => '/apply',
            'services.automation_worker.token' => 'test-token',
            'apply.document_disk' => 's3',
            'apply.screenshot_disk' => 's3',
        ]);

        // The whole point of a fixture harness: no third-party host is reachable
        // from this suite, in CI or on a laptop. Anything but the faked worker
        // raises instead of leaving the test quietly dependent on a live board.
        Http::preventStrayRequests();

        $this->user = User::factory()->create();
    }

    /** @return array<string, array{0: string}> */
    public static function adapterProvider(): array
    {
        return [
            'greenhouse' => ['greenhouse'],
            'lever' => ['lever'],
            'workday' => ['workday'],
            'linkedin' => ['linkedin'],
        ];
    }

    /**
     * The assertion the worker-level tests cannot make: the configured
     * selectors actually find the elements they name.
     */
    #[DataProvider('adapterProvider')]
    public function test_every_configured_selector_resolves_in_the_fixture(string $ats): void
    {
        $spec = self::ADAPTERS[$ats];
        $selectors = config('apply.adapters.'.$ats.'.selectors');

        foreach ($spec['exact'] as $name => $page) {
            $this->assertResolvesToOneElement($ats, $page, 'selectors.'.$name, (string) $selectors[$name]);
        }

        foreach ($spec['any'] as $name => $page) {
            $this->assertResolvesToSomething($ats, $page, 'selectors.'.$name, (string) $selectors[$name]);
        }

        // Screening questions are selectors too, and the likeliest to rot:
        // they are matched by substring against tenant-defined field names.
        foreach ((array) config('apply.adapters.'.$ats.'.questions', []) as $key => $question) {
            $this->assertResolvesToOneElement(
                $ats,
                $spec['questions_page'],
                'questions.'.$key.'.selector',
                (string) $question['selector']
            );
        }
    }

    /**
     * Coverage, both ways: every selector in config is either checked above or
     * declared empty on purpose, and every selector the adapter compiles into a
     * script is one of the checked ones.
     *
     * Without this, adding a field to an adapter would silently add an
     * unvalidated selector, and the harness would rot along with it.
     */
    #[DataProvider('adapterProvider')]
    public function test_every_selector_the_adapter_compiles_is_covered_by_a_fixture(string $ats): void
    {
        $spec = self::ADAPTERS[$ats];
        $selectors = (array) config('apply.adapters.'.$ats.'.selectors');

        $checked = array_keys($spec['exact'] + $spec['any']);
        $declared = array_merge($checked, $spec['unused']);

        foreach (array_keys($selectors) as $name) {
            $this->assertContains(
                $name,
                $declared,
                "apply.adapters.{$ats}.selectors.{$name} has no fixture page: add it to ApplyFixtureSelectorTest::ADAPTERS."
            );
        }

        foreach ($spec['unused'] as $name) {
            $this->assertSame('', (string) $selectors[$name], "{$ats}.{$name} is declared unused but is configured.");
        }

        $covered = array_map(
            fn (string $name): string => (string) $selectors[$name],
            $checked
        );

        foreach ((array) config('apply.adapters.'.$ats.'.questions', []) as $question) {
            $covered[] = (string) $question['selector'];
        }

        foreach ($this->compileSteps($ats) as $step) {
            if (! isset($step['selector'])) {
                continue;
            }

            $this->assertContains(
                $step['selector'],
                $covered,
                "{$ats} compiled a step against a selector no fixture checks: {$step['selector']}"
            );
        }
    }

    /**
     * A CAPTCHA or sign-in wall is a dead end, not a flaky run: it goes to a
     * person with its screenshots and is never retried (Requirement 9.6).
     *
     * The wall text here is the fixture's own text, read back the way the worker
     * reads it, so the config markers are matched against markup rather than
     * against a string invented by the test.
     */
    #[DataProvider('adapterProvider')]
    public function test_a_wall_fixture_is_handed_to_a_person_rather_than_retried(string $ats): void
    {
        $spec = self::ADAPTERS[$ats];

        $this->pageText = $this->fixtureText($ats, 'wall');
        $this->activeAts = $ats;

        $this->fakeWorker(
            fn (array $steps): array => $this->failedBody($steps, $this->wallFailIndex($steps), [
                'title' => $this->fixtureTitle($ats, 'wall'),
                'html' => $this->fixtureHtml($ats, 'wall'),
            ]),
            self::WORKER
        );

        $result = $this->adapterFor($ats)->apply($this->context($ats));

        $this->assertTrue(
            $result->needsHumanReview(),
            "{$ats}: a wall should need review, got {$result->status}: ".(string) $result->failureReason
        );
        $this->assertFalse($result->isRetryable(), "{$ats}: a wall must never be retried.");
        $this->assertNotEmpty($result->screenshotPaths ?: $result->metadata, "{$ats}: the wall run left no evidence.");

        Http::assertSentCount(1);
    }

    /**
     * Where a run against a wall actually dies: the first element wait *after*
     * the page text was read back.
     *
     * Not simply the first wait in the script. Workday and LinkedIn wait on the
     * posting page before reading the page, and failing there would throw away
     * the evidence the whole detection depends on — the text the worker read.
     * A real wall lets the body load (that is what the wall *is*) and then
     * starves every form selector, which is what this reproduces.
     *
     * @param  array<int, array<string, mixed>>  $steps
     */
    private function wallFailIndex(array $steps): int
    {
        $afterPageRead = false;

        foreach ($steps as $index => $step) {
            if ($step['kind'] === 'readText' && str_ends_with((string) ($step['name'] ?? ''), '_page')) {
                $afterPageRead = true;

                continue;
            }

            if ($afterPageRead && $step['kind'] === 'waitFor') {
                return $index;
            }
        }

        return $this->indexOf($steps, 'waitFor');
    }

    /** The step script the adapter compiled, read off the request the worker was sent. */
    private function compileSteps(string $ats): array
    {
        $this->activeAts = $ats;
        $captured = [];

        $this->fakeWorker(function (array $steps) use (&$captured): array {
            $captured = $steps;

            return $this->successBody($steps);
        }, self::WORKER);

        $this->adapterFor($ats)->apply($this->context($ats));

        $this->assertNotEmpty($captured, "{$ats}: the adapter compiled no steps.");

        return $captured;
    }

    private function adapterFor(string $ats): ApplyAdapter
    {
        if ($ats === 'linkedin') {
            // Consent is a precondition for the adapter running at all; the
            // opt-in itself is LinkedInEasyApplyAdapterTest's subject.
            UserAutomationSetting::updateOrCreate(
                ['user_id' => $this->user->id],
                ['linkedin_auto_apply_opt_in' => true] + UserAutomationSetting::defaultsFor($this->user->id),
            );
        }

        return $this->app->make(self::ADAPTERS[$ats]['adapter']);
    }

    private function context(string $ats): ApplyContext
    {
        $profile = new UserProfile([
            'user_id' => $this->user->id,
            'location' => ['city' => 'London', 'country' => 'UK'],
            'linkedin_url' => 'https://linkedin.com/in/ada',
            'github_url' => 'https://github.com/ada',
            'portfolio_url' => 'https://ada.dev',
        ]);
        $profile->forceFill(['id' => 9]);

        $job = new JobListing;
        $job->forceFill(['id' => 31, 'application_url' => self::ADAPTERS[$ats]['url']]);

        return new ApplyContext(
            job: $job,
            user: $this->user,
            profile: $profile,
            tailoredResumePath: self::RESUME_KEY,
            coverLetterPath: self::COVER_LETTER_KEY,
            answers: self::ANSWERS + ['current_company' => 'Analytical Engines Ltd'],
        );
    }

    /** Which ATS the shared worker fake is currently standing in for. */
    private string $activeAts = 'greenhouse';

    private function applyUrl(): string
    {
        return self::ADAPTERS[$this->activeAts]['url'];
    }

    private function confirmedUrl(): string
    {
        return self::ADAPTERS[$this->activeAts]['confirmed_url'];
    }
}

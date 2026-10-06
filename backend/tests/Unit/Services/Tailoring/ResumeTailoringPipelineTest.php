<?php

namespace Tests\Unit\Services\Tailoring;

use App\Exceptions\LatexEngineException;
use App\Exceptions\ResumeCompilationException;
use App\Exceptions\ResumeTailoringException;
use App\Models\JobListing;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\Latex\LatexEngine;
use App\Services\Latex\LatexRenderService;
use App\Services\ModelCompletionResult;
use App\Services\ModelRouterService;
use App\Services\ModelTierContext;
use App\Services\Tailoring\ResumeTailoringPipeline;
use App\Services\Tailoring\ResumeTailoringService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;
use Throwable;

/**
 * The compile-failure corrective loop of Requirement 6.4 (task 11.4).
 *
 * Both collaborators are real: the tailoring service runs against a recording
 * fake router, and the render service runs against a shell script standing in
 * for the TeX engine (the technique from
 * {@see \Tests\Unit\Services\Latex\LatexRenderServiceTest}, which explains why
 * at length). Nothing here needs tectonic installed, and nothing touches the
 * database.
 *
 * Faking the *engine* rather than the render service is the point of the setup:
 * what is under test is how the loop reacts to a compile failure, and a stub
 * that fails on its first invocation and succeeds on its second reproduces that
 * exactly, including the retained render directory and the error text that has
 * to reach the next prompt.
 */
class ResumeTailoringPipelineTest extends TestCase
{
    private FakePipelineRouter $router;

    /** Scratch root for the engine stub, its invocation counter, and render output. */
    private string $tempRoot;

    /** File the stub engine counts its invocations in. */
    private string $counterPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempRoot = sys_get_temp_dir().'/tailoring-pipeline-test-'.bin2hex(random_bytes(6));
        $this->counterPath = $this->tempRoot.'/invocations';
        File::ensureDirectoryExists($this->tempRoot.'/work');

        config([
            'latex.engine' => 'tectonic',
            'latex.working_directory' => $this->tempRoot.'/work',
            'latex.timeout' => 30,
            'latex.tailoring.max_attempts' => 2,
            'latex.tailoring.default_template_key' => 'default',
            'pipeline.latex_retry_max' => 2,
        ]);

        // Both failure paths log deliberately.
        Log::spy();

        $this->router = new FakePipelineRouter;
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tempRoot);

        parent::tearDown();
    }

    private function pipeline(): ResumeTailoringPipeline
    {
        // A fresh LatexEngine per pipeline: it memoises its binary lookup, so one
        // reused across a config change would answer from the old config.
        return new ResumeTailoringPipeline(
            new ResumeTailoringService($this->router),
            new LatexRenderService(new LatexEngine),
        );
    }

    /**
     * Install an engine stub that fails its first `$failures` compiles and
     * succeeds afterwards.
     *
     * The `--version` branch is answered before the counter is touched because
     * the render service probes the version when logging a *failure*; counting it
     * would make every failure consume two of the budget below.
     *
     * Named `stubEngine` rather than anything shorter for the same reason as in
     * the render service's own test: `TestCase::run()` is final.
     */
    private function stubEngine(int $failures): void
    {
        $script = str_replace(
            ['__COUNTER__', '__FAILURES__'],
            [$this->counterPath, (string) $failures],
            <<<'SH'
                #!/bin/sh
                case "$1" in --version) echo "stub-engine 0.0.0"; exit 0;; esac

                n=$(cat "__COUNTER__" 2>/dev/null || echo 0)
                n=$((n + 1))
                echo "$n" > "__COUNTER__"

                if [ "$n" -le __FAILURES__ ]; then
                  echo "! Overfull \hbox (128.5pt too wide) in paragraph at lines 42--45" >&2
                  exit 1
                fi

                for last in "$@"; do :; done
                printf '%%PDF-1.4 stub' > "${last%.tex}.pdf"
                exit 0
                SH
        );

        $path = $this->tempRoot.'/engine.sh';
        File::put($path, $script."\n");
        chmod($path, 0700);

        config(['latex.binary' => $path]);
    }

    /** How many times the stub engine was asked to compile. */
    private function compileCount(): int
    {
        return is_file($this->counterPath) ? (int) trim((string) File::get($this->counterPath)) : 0;
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

    private function profile(array $overrides = []): UserProfile
    {
        $profile = new UserProfile(array_merge([
            'user_id' => 7,
            'skills' => ['primary' => ['Laravel', 'PostgreSQL'], 'secondary' => ['Redis']],
            'location' => ['city' => 'Austin', 'state' => 'TX', 'country' => 'US'],
            'experience' => [
                [
                    'company' => 'Analytical & Co',
                    'position' => 'Staff Engineer',
                    'duration' => 30,
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

        $profile->setRelation('user', new User(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']));

        return $profile;
    }

    /** A well-formed tailoring response. */
    private function tailoringJson(array $overrides = []): string
    {
        return (string) json_encode(array_merge([
            'headline' => 'Senior Backend Engineer',
            'summary' => 'Backend engineer with six years building Laravel services on PostgreSQL.',
            'emphasizedSkills' => ['PostgreSQL', 'Laravel'],
            'experience' => [
                ['index' => 0, 'bullets' => ['Owned a billing service handling 40% of revenue']],
            ],
        ], $overrides));
    }

    /* ------------------------------------------------------------------
     | First compile succeeds: no loop at all
     | ----------------------------------------------------------------- */

    public function test_a_document_that_compiles_first_time_costs_one_model_call_and_one_render(): void
    {
        $this->stubEngine(failures: 0);
        $this->router->willReturn($this->tailoringJson());

        $compiled = $this->pipeline()->tailorAndCompile($this->listing(), $this->profile());

        $this->assertSame(1, $compiled->compileAttempts);
        $this->assertSame([], $compiled->compileErrors);
        $this->assertCount(1, $this->router->calls);
        $this->assertSame(1, $this->compileCount());

        $this->assertTrue($compiled->document->success);
        $this->assertFileExists($compiled->pdfPath());
        $this->assertStringContainsString('\documentclass', $compiled->texSource());

        // The tailoring half is carried through intact, because task 11.5 diffs
        // its facts and task 11.7 persists its provenance.
        $this->assertSame('default', $compiled->templateKey);
        $this->assertSame('Analytical & Co', $compiled->tailored->facts[0]['company']);
        $this->assertSame('vendor/standard-1', $compiled->tailored->modelUsed);
    }

    public function test_an_explicit_template_key_is_honoured(): void
    {
        $this->stubEngine(failures: 0);
        $this->router->willReturn($this->tailoringJson());

        $compiled = $this->pipeline()->tailorAndCompile($this->listing(), $this->profile(), 'default');

        $this->assertSame('default', $compiled->templateKey);
        $this->assertTrue($compiled->document->success);
    }

    public function test_a_misconfigured_default_template_key_fails_loudly_instead_of_falling_back(): void
    {
        // Task 11.8, template selection. `default_template_key` is an env-driven
        // string and the whitelist in LatexRenderService is a code constant, so
        // the two can drift — a deployment setting TAILORING_TEMPLATE_KEY to a
        // template that was renamed or never shipped.
        //
        // The behaviour that matters is what happens *then*: silently rendering
        // some other template would put a resume the user did not choose in front
        // of an employer, and it would do it on every run without ever showing up
        // as a failure. So the run dies with a message naming the key and the
        // known ones, and — because the exception is not a compile failure — it
        // does not consume the retry budget re-prompting a model that cannot fix
        // the config.
        config(['latex.tailoring.default_template_key' => 'brutalist']);
        $this->stubEngine(failures: 0);
        $this->router->willReturn($this->tailoringJson());

        try {
            $this->pipeline()->tailorAndCompile($this->listing(), $this->profile(), null);
            $this->fail('an unknown configured template key should not have rendered.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('brutalist', $e->getMessage());
            $this->assertStringContainsString('Known templates: default', $e->getMessage());
        }

        $this->assertSame(0, $this->compileCount());
        $this->assertSame([], glob($this->tempRoot.'/work/*') ?: []);
    }

    /* ------------------------------------------------------------------
     | One compile failure, then success
     | ----------------------------------------------------------------- */

    public function test_a_compile_failure_re_prompts_the_model_and_the_second_render_succeeds(): void
    {
        $this->stubEngine(failures: 1);
        $this->router->willReturn($this->tailoringJson());
        $this->router->willReturn($this->tailoringJson([
            'summary' => 'Backend engineer; six years of Laravel and PostgreSQL delivery.',
        ]));

        $compiled = $this->pipeline()->tailorAndCompile($this->listing(), $this->profile());

        $this->assertTrue($compiled->document->success);
        $this->assertSame(2, $compiled->compileAttempts);
        $this->assertSame(2, $this->compileCount());
        $this->assertCount(2, $this->router->calls);

        // The recovered failure is kept: a template needing two goes is a bug
        // somebody should see, even on a run that succeeded.
        $this->assertCount(1, $compiled->compileErrors);
        $this->assertStringContainsString('Overfull', $compiled->compileErrors[0]);

        // The second response is the one that shipped.
        $this->assertStringContainsString('six years of Laravel', $compiled->tailored->forTemplate()['summary']);
    }

    public function test_the_corrective_prompt_carries_the_engine_error_and_the_content_that_failed(): void
    {
        $this->stubEngine(failures: 1);
        $this->router->willReturn($this->tailoringJson());
        $this->router->willReturn($this->tailoringJson());

        $this->pipeline()->tailorAndCompile($this->listing(), $this->profile());

        $first = $this->router->calls[0]['messages'][0]['content'];
        $retry = $this->router->calls[1]['messages'][0]['content'];

        // The first call is the ordinary prompt; the correction is not pre-loaded.
        $this->assertStringNotContainsString('failed to typeset', $first);

        // The engine's actual complaint, verbatim.
        $this->assertStringContainsString('failed to typeset', $retry);
        $this->assertStringContainsString('Overfull \hbox (128.5pt too wide)', $retry);

        // Next to the values it has to be read against, so the error is
        // actionable rather than just alarming.
        $this->assertStringContainsString('Owned a billing service handling 40% of revenue', $retry);
        $this->assertStringContainsString('Backend engineer with six years building Laravel', $retry);

        // Asks for different *content*, in the terms the model controls — never
        // for corrected markup, and the .tex is not shown at all.
        $this->assertStringContainsString('Shorten anything long', $retry);
        $this->assertStringContainsString('plain ASCII', $retry);
        $this->assertStringNotContainsString('\documentclass', $retry);
        $this->assertStringNotContainsString('\begin{document}', $retry);
    }

    /* ------------------------------------------------------------------
     | Budget exhaustion
     | ----------------------------------------------------------------- */

    public function test_exhausting_the_budget_throws_with_every_attempts_compile_error(): void
    {
        config(['pipeline.latex_retry_max' => 2]);
        // Never compiles, whatever the model says.
        $this->stubEngine(failures: 99);

        foreach (range(1, 3) as $ignored) {
            $this->router->willReturn($this->tailoringJson());
        }

        try {
            $this->pipeline()->tailorAndCompile($this->listing(), $this->profile());
            $this->fail('Expected a ResumeCompilationException once the compile budget was spent.');
        } catch (ResumeCompilationException $e) {
            // Two retries means three renders, not two.
            $this->assertCount(3, $e->attempts);
            $this->assertSame([1, 2, 3], array_column($e->attempts, 'attempt'));
            $this->assertCount(3, $e->compileErrors());

            foreach ($e->compileErrors() as $error) {
                $this->assertStringContainsString('Overfull', $error);
            }

            // Identical every time, which is the signal that re-prompting was
            // never going to help and the template is at fault.
            $this->assertTrue($e->failedIdentically());

            // The retained render directories, where the .log files sit.
            $this->assertCount(3, $e->renderDirectories());
            $this->assertDirectoryExists($e->renderDirectories()[0]);

            $this->assertSame('default', $e->context['template_key']);
            $this->assertSame(3, $e->context['compile_attempts']);
            $this->assertNotSame('', $e->reviewReason());
        }

        $this->assertSame(3, $this->compileCount());
        $this->assertCount(3, $this->router->calls);
    }

    public function test_a_zero_retry_budget_turns_the_first_compile_failure_into_a_review_item(): void
    {
        config(['pipeline.latex_retry_max' => 0]);
        $this->stubEngine(failures: 99);
        $this->router->willReturn($this->tailoringJson());

        try {
            $this->pipeline()->tailorAndCompile($this->listing(), $this->profile());
            $this->fail('Expected a ResumeCompilationException with the loop disabled.');
        } catch (ResumeCompilationException $e) {
            $this->assertCount(1, $e->attempts);
            // One render only, so there is nothing to compare and no claim made.
            $this->assertFalse($e->failedIdentically());
        }

        $this->assertSame(1, $this->compileCount());
        $this->assertCount(1, $this->router->calls);
    }

    /* ------------------------------------------------------------------
     | Environment faults are not the model's problem
     | ----------------------------------------------------------------- */

    public function test_a_missing_engine_propagates_without_consuming_the_retry_budget(): void
    {
        config(['latex.binary' => $this->tempRoot.'/not-installed']);
        $this->router->willReturn($this->tailoringJson());

        try {
            $this->pipeline()->tailorAndCompile($this->listing(), $this->profile());
            $this->fail('Expected the LatexEngineException to propagate.');
        } catch (LatexEngineException $e) {
            $this->assertStringContainsString('tectonic', $e->getMessage());
        }

        // One model call, no retries: every attempt would fail identically, and a
        // run that spent its budget here would report a compile problem when the
        // truth is a broken deployment.
        $this->assertCount(1, $this->router->calls);
        $this->assertSame(0, $this->compileCount());
    }

    public function test_a_tailoring_failure_aborts_the_run_rather_than_entering_the_compile_loop(): void
    {
        $this->stubEngine(failures: 0);

        // Both response attempts unusable, so the tailoring service gives up.
        $this->router->willReturn($this->tailoringJson(['summary' => '']));
        $this->router->willReturn($this->tailoringJson(['summary' => '   ']));

        $this->expectException(ResumeTailoringException::class);

        try {
            $this->pipeline()->tailorAndCompile($this->listing(), $this->profile());
        } finally {
            // The response budget was spent in full and the compile budget was
            // never touched — there was no document to compile.
            $this->assertCount(2, $this->router->calls);
            $this->assertSame(0, $this->compileCount());
        }
    }

    /* ------------------------------------------------------------------
     | The two budgets are independent
     | ----------------------------------------------------------------- */

    public function test_each_compile_retry_gets_a_fresh_response_retry_allowance(): void
    {
        config([
            'latex.tailoring.max_attempts' => 2,
            'pipeline.latex_retry_max' => 1,
        ]);
        $this->stubEngine(failures: 1);

        // Compile attempt 1: one unusable response, then a good one.
        $this->router->willReturn($this->tailoringJson(['summary' => '']));
        $this->router->willReturn($this->tailoringJson());
        // Compile attempt 2, after the compile failure: the response budget is
        // fresh, so an unusable response here still earns its own retry.
        $this->router->willReturn($this->tailoringJson(['summary' => '   ']));
        $this->router->willReturn($this->tailoringJson([
            'summary' => 'Backend engineer; Laravel and PostgreSQL.',
        ]));

        $compiled = $this->pipeline()->tailorAndCompile($this->listing(), $this->profile());

        $this->assertTrue($compiled->document->success);
        // Four model calls for two renders: 2 * (1 response failure + 1 success).
        $this->assertCount(4, $this->router->calls);
        $this->assertSame(2, $compiled->compileAttempts);
        $this->assertSame(2, $this->compileCount());

        // The response-level counter restarted, rather than continuing from the
        // first render's spend.
        $this->assertSame(2, $compiled->tailored->attempts);

        // And the two corrections do not blur together: call 3 opens the second
        // render with the compile error, while call 4 — a response failure inside
        // it — replaces that with the stricter-format note.
        $this->assertStringContainsString('failed to typeset', $this->router->calls[2]['messages'][0]['content']);
        $this->assertStringContainsString(
            'Return ONLY valid JSON matching this schema',
            $this->router->calls[3]['messages'][0]['content']
        );
    }

    public function test_a_compile_failure_does_not_count_against_the_response_budget(): void
    {
        // One response attempt allowed, so a run that mistook a compile failure
        // for a response failure would abort instead of retrying.
        config([
            'latex.tailoring.max_attempts' => 1,
            'pipeline.latex_retry_max' => 2,
        ]);
        $this->stubEngine(failures: 2);

        $this->router->willReturn($this->tailoringJson());
        $this->router->willReturn($this->tailoringJson());
        $this->router->willReturn($this->tailoringJson());

        $compiled = $this->pipeline()->tailorAndCompile($this->listing(), $this->profile());

        $this->assertTrue($compiled->document->success);
        $this->assertSame(3, $compiled->compileAttempts);
        $this->assertSame(1, $compiled->tailored->attempts);
        $this->assertCount(3, $this->router->calls);
    }
}

/**
 * Records what the pipeline's tailoring step asked for and returns queued
 * responses.
 *
 * A local copy rather than a reuse of the one in
 * {@see ResumeTailoringServiceTest}: that class is declared inside a test file,
 * so referencing it from here would make this suite depend on PHPUnit having
 * loaded that file first. A subclass rather than a mock, for the reason given
 * there — PHP enforces the real signature, so a change to
 * {@see ModelRouterService::complete()} breaks this loudly instead of silently.
 */
class FakePipelineRouter extends ModelRouterService
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
            modelUsed: 'vendor/standard-1',
            tierUsed: 'standard',
            inputTokens: 1400,
            outputTokens: 260,
            estimatedCostUsd: 0.0039,
        );
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
        ];

        if ($this->queue === []) {
            throw new \LogicException('FakePipelineRouter received an unexpected call for task '.$taskType);
        }

        return array_shift($this->queue);
    }
}

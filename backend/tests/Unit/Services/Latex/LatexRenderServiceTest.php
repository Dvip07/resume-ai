<?php

namespace Tests\Unit\Services\Latex;

use App\Exceptions\LatexEngineException;
use App\Services\Latex\LatexEngine;
use App\Services\Latex\LatexRenderService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Render-and-compile behaviour of {@see LatexRenderService} (Requirements 6.1,
 * 6.2).
 *
 * ## No TeX engine is involved
 *
 * Every test here points `config('latex.binary')` at a shell script written into
 * a temporary directory by {@see stubEngine()}. That is not a convenience: the
 * suite has to pass on a developer machine and in CI where tectonic is not
 * installed, and — more importantly — the behaviour under test is *how this
 * class reacts to an engine's exit status, streams, and output artefacts*, which
 * a stub can produce on demand and a real engine cannot. A test that needed a
 * genuine failing compile would have to construct a broken LaTeX document and
 * hope it stays broken across engine versions.
 *
 * The consequence is stated plainly: nothing here proves the template compiles.
 * That is the job of an integration check in an environment that has the engine.
 */
class LatexRenderServiceTest extends TestCase
{
    /** Scratch root for stub scripts and render output; removed in tearDown. */
    private string $tempRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempRoot = sys_get_temp_dir().'/latex-render-test-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->tempRoot.'/work');

        config(['latex.engine' => 'tectonic']);
        config(['latex.working_directory' => $this->tempRoot.'/work']);
        config(['latex.timeout' => 30]);

        // The failure paths log deliberately; without this the suite prints
        // warnings that look like real problems.
        Log::spy();
    }

    protected function tearDown(): void
    {
        // Covers the stub scripts, every render directory, and any PDF kept after
        // a successful render — nothing this class writes lives outside here.
        File::deleteDirectory($this->tempRoot);

        parent::tearDown();
    }

    private function service(): LatexRenderService
    {
        // A fresh LatexEngine per call: it memoises its binary lookup per
        // instance, so reusing one across a config change would answer from the
        // old config.
        return new LatexRenderService(new LatexEngine);
    }

    /**
     * Write an executable stub standing in for the TeX engine and point config at
     * it.
     *
     * Named `stubEngine` rather than anything shorter because PHPUnit's
     * `TestCase::run()` is final and cannot be shadowed.
     */
    private function stubEngine(string $body): void
    {
        $path = $this->tempRoot.'/engine-'.bin2hex(random_bytes(4)).'.sh';
        File::put($path, "#!/bin/sh\n".$body."\n");
        chmod($path, 0700);

        config(['latex.binary' => $path]);
    }

    /**
     * A stub that behaves like a successful compile: it writes a PDF where the
     * service expects to find one.
     *
     * The path is derived from the last argument (the `.tex` file) rather than
     * hard-coded, which keeps the stub honest about *where* the service asked for
     * output and works for either engine's argument spelling.
     */
    private function stubSuccessfulEngine(): void
    {
        $this->stubEngine(<<<'SH'
            for last in "$@"; do :; done
            printf '%%PDF-1.4 stub' > "${last%.tex}.pdf"
            exit 0
            SH);
    }

    /**
     * @return array<string, mixed>
     */
    private function content(): array
    {
        return [
            'name' => 'Ada Lovelace',
            'headline' => 'Senior Backend Engineer',
            'contact' => ['ada@example.com', 'London, UK'],
            'summary' => 'Backend engineer with 10+ years building R&D systems.',
            'skills' => [
                ['label' => 'Languages', 'items' => ['PHP', 'Go']],
            ],
            'experience' => [
                [
                    'position' => 'Staff Engineer',
                    'company' => 'Analytical & Co',
                    'location' => 'Remote',
                    'dates' => '2019 - Present',
                    'bullets' => ['Cut p99 latency by 40%'],
                ],
            ],
            'education' => [
                [
                    'degree' => 'BSc',
                    'field' => 'Mathematics',
                    'institution' => 'University of London',
                    'location' => 'London',
                    'dates' => '2009',
                ],
            ],
        ];
    }

    public function test_it_renders_the_tex_source_and_returns_a_successful_document(): void
    {
        $this->stubSuccessfulEngine();

        $document = $this->service()->renderResume('default', $this->content());

        $this->assertTrue($document->success);
        $this->assertNull($document->compileError);
        $this->assertSame(0, $document->exitCode);

        $this->assertNotNull($document->pdfPath);
        $this->assertFileExists($document->pdfPath);

        // The PDF outlives the render directory it was compiled in, because the
        // caller uploads it to S3 after this returns.
        $this->assertStringStartsWith($this->tempRoot.'/work/', $document->pdfPath);
        $this->assertSame([], glob($this->tempRoot.'/work/*', GLOB_ONLYDIR) ?: []);
    }

    public function test_the_rendered_source_is_a_complete_escaped_latex_document(): void
    {
        $this->stubSuccessfulEngine();

        $tex = $this->service()->renderResume('default', $this->content())->texSource;

        $this->assertStringContainsString('\documentclass', $tex);
        $this->assertStringContainsString('\begin{document}', $tex);
        $this->assertStringContainsString('\end{document}', $tex);

        $this->assertStringContainsString('Ada Lovelace', $tex);
        $this->assertStringContainsString('Senior Backend Engineer', $tex);
        $this->assertStringContainsString('University of London', $tex);

        // Requirement 6.3 holding through the render path, not just in the
        // escaper's own unit test: an ampersand in a company name and a percent
        // sign in a model-written bullet both arrive escaped. Unescaped, the
        // first is an alignment error and the second silently comments out the
        // rest of the line.
        $this->assertStringContainsString('Analytical \& Co', $tex);
        $this->assertStringContainsString('40\%', $tex);
        $this->assertStringNotContainsString('Analytical & Co', $tex);
    }

    public function test_an_unknown_template_key_throws_without_attempting_a_render(): void
    {
        // No stub engine is configured. If the service tried to compile, it would
        // fail on the missing engine instead — so this also pins the ordering:
        // the key is validated first.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown resume template [../mail/welcome]');

        $this->service()->renderResume('../mail/welcome', $this->content());
    }

    public function test_an_unknown_template_key_never_reaches_the_view_factory(): void
    {
        // Task 11.8, template selection. The test above proves the *caller* gets
        // an exception; this proves the thing that makes the whitelist a security
        // boundary rather than a validation nicety — the rejected string is never
        // handed to the view factory at all. A guard that threw only after calling
        // View::make would already have resolved a path from user input by the
        // time it noticed.
        View::spy();

        foreach (['../mail/welcome', 'latex::resume', 'resume', '', 'default/../../etc/passwd'] as $key) {
            try {
                $this->service()->renderResume($key, $this->content());
                $this->fail(sprintf('template key [%s] should have been rejected.', $key));
            } catch (InvalidArgumentException) {
                // expected
            }
        }

        View::shouldNotHaveReceived('make');
    }

    public function test_the_whitelist_is_matched_exactly_and_not_loosely(): void
    {
        // Case folding or trimming here would widen the map's key space by
        // guesswork, and the key is persisted on `tailored_documents.template_key`
        // — two spellings of one template would fragment that column. Callers
        // that want leniency trim before they call (the pipeline does).
        foreach (['Default', 'DEFAULT', ' default', 'default '] as $key) {
            try {
                $this->service()->renderResume($key, $this->content());
                $this->fail(sprintf('template key [%s] should have been rejected.', $key));
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('Known templates: default', $e->getMessage());
            }
        }
    }

    public function test_the_configured_default_template_key_is_one_the_whitelist_accepts(): void
    {
        // The link between `config('latex.tailoring.default_template_key')` — which
        // ResumeTailoringService resolves for every caller that does not choose a
        // template — and the whitelist that has to accept the result. Nothing else
        // in the suite fails if the shipped default and the shipped map disagree,
        // because every other test names 'default' literally.
        $this->stubSuccessfulEngine();

        $configured = (string) config('latex.tailoring.default_template_key');

        $this->assertNotSame('', $configured);
        $this->assertTrue($this->service()->renderResume($configured, $this->content())->success);
    }

    public function test_a_missing_engine_throws_rather_than_returning_a_failed_document(): void
    {
        config(['latex.binary' => $this->tempRoot.'/not-installed']);

        // An environment fault, not a document fault: returning success=false
        // here would send the retry loop off to re-prompt the model, and every
        // attempt would fail identically.
        $this->expectException(LatexEngineException::class);

        $this->service()->renderResume('default', $this->content());
    }

    public function test_a_non_zero_exit_yields_a_failed_document_with_the_engine_output(): void
    {
        $this->stubEngine(<<<'SH'
            echo "! Undefined control sequence l.42 \badmacro" >&2
            exit 1
            SH);

        $document = $this->service()->renderResume('default', $this->content());

        $this->assertFalse($document->success);
        $this->assertNull($document->pdfPath);
        $this->assertSame(1, $document->exitCode);
        $this->assertStringContainsString('Undefined control sequence', (string) $document->compileError);

        // The .tex is kept on failure: it is what the corrective prompt (Req 6.4)
        // has to reason about.
        $this->assertStringContainsString('\documentclass', $document->texSource);

        // And the render directory survives, because the .log inside it is the
        // real debugging artefact.
        $this->assertNotNull($document->workingDirectory);
        $this->assertDirectoryExists($document->workingDirectory);
        $this->assertFileExists($document->workingDirectory.'/ada_lovelace.tex');
    }

    public function test_a_clean_exit_without_a_pdf_is_still_a_failure(): void
    {
        $this->stubEngine('exit 0');

        $document = $this->service()->renderResume('default', $this->content());

        $this->assertFalse($document->success);
        $this->assertNull($document->pdfPath);
        $this->assertStringContainsString('produced no PDF', (string) $document->compileError);
    }

    public function test_a_hanging_engine_is_killed_at_the_timeout_and_reported_as_a_failure(): void
    {
        config(['latex.timeout' => 1]);
        // The `--version` branch is not decoration: the failure path logs the
        // engine version, so a stub that hung on every invocation would also hang
        // that probe and add its own timeout to the suite. Answering it keeps this
        // test measuring the compile timeout and nothing else.
        $this->stubEngine(<<<'SH'
            case "$1" in --version) echo "stub-engine 0.0.0"; exit 0;; esac
            exec sleep 20
            SH);

        $document = $this->service()->renderResume('default', $this->content());

        $this->assertFalse($document->success);
        $this->assertNull($document->pdfPath);
        $this->assertStringContainsString('timed out after 1 seconds', (string) $document->compileError);
        $this->assertNotNull($document->workingDirectory);
        $this->assertDirectoryExists($document->workingDirectory);
    }

    /**
     * The cover-letter equivalent of {@see content()} — the payload
     * `renderCoverLetter()` will be handed once the tailoring service above it
     * exists (task 12.3's later slices). Contract is documented at the top of
     * `resources/views/latex/cover-letter.tex.blade.php`.
     *
     * @return array<string, mixed>
     */
    private function letterContent(): array
    {
        return [
            'name' => 'Ada Lovelace',
            'contact' => ['ada@example.com', 'London, UK'],
            'date' => '17 September 2026',
            'recipient_name' => 'Alex Reed',
            'recipient_title' => 'Engineering Manager',
            'company' => 'Analytical & Co',
            'company_address' => ['1 Bridge Street', 'London EC1A 1AA'],
            'job_title' => 'Senior Backend Engineer',
            'paragraphs' => [
                'I am writing to apply for the Senior Backend Engineer role.',
                'At my current role I cut p99 latency by 40% across the queue tier.',
            ],
            'closing' => 'Sincerely,',
            'signature' => 'Ada Lovelace',
        ];
    }

    public function test_it_renders_and_compiles_a_cover_letter(): void
    {
        $this->stubSuccessfulEngine();

        $document = $this->service()->renderCoverLetter('default', $this->letterContent());

        $this->assertTrue($document->success);
        $this->assertNull($document->compileError);
        $this->assertNotNull($document->pdfPath);
        $this->assertFileExists($document->pdfPath);

        // Same cleanup contract as a resume render: the PDF is kept, the render
        // directory is not.
        $this->assertStringStartsWith($this->tempRoot.'/work/', $document->pdfPath);
        $this->assertSame([], glob($this->tempRoot.'/work/*', GLOB_ONLYDIR) ?: []);

        // The filename says which document this is. Both kinds are generated for
        // the same person and the same listing, so a cover letter arriving as
        // `ada_lovelace.pdf` would be indistinguishable from the resume.
        $this->assertStringContainsString('ada_lovelace_cover_letter.pdf', $document->pdfPath);
    }

    public function test_the_rendered_cover_letter_source_is_a_complete_escaped_latex_document(): void
    {
        $this->stubSuccessfulEngine();

        $tex = $this->service()->renderCoverLetter('default', $this->letterContent())->texSource;

        $this->assertStringContainsString('\documentclass', $tex);
        $this->assertStringContainsString('\begin{document}', $tex);
        $this->assertStringContainsString('\end{document}', $tex);

        $this->assertStringContainsString('Ada Lovelace', $tex);
        $this->assertStringContainsString('Dear Alex Reed,', $tex);
        $this->assertStringContainsString('Senior Backend Engineer', $tex);
        $this->assertStringContainsString('Sincerely,', $tex);

        // Requirement 6.3's escaping guarantee holding through the cover-letter
        // path too: an ampersand in the company name is an alignment error
        // unescaped, and a percent in model prose comments out the rest of the
        // paragraph.
        $this->assertStringContainsString('Analytical \& Co', $tex);
        $this->assertStringContainsString('40\%', $tex);
        $this->assertStringNotContainsString('Analytical & Co', $tex);
    }

    public function test_an_unknown_cover_letter_template_key_never_reaches_the_view_factory(): void
    {
        // The resume test's counterpart, for the same reason: the whitelist is a
        // security boundary, so a rejected string must not be resolved to a path
        // before it is rejected.
        View::spy();

        foreach (['../mail/welcome', 'latex::cover-letter', 'cover-letter', '', 'default/../../etc/passwd'] as $key) {
            try {
                $this->service()->renderCoverLetter($key, $this->letterContent());
                $this->fail(sprintf('cover letter template key [%s] should have been rejected.', $key));
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('Unknown cover letter template', $e->getMessage());
            }
        }

        View::shouldNotHaveReceived('make');
    }

    public function test_the_cover_letter_whitelist_is_matched_exactly_and_not_loosely(): void
    {
        // Same argument as the resume whitelist: the key is persisted on
        // `tailored_documents.template_key`, and accepting two spellings of one
        // template would fragment that column.
        foreach (['Default', 'DEFAULT', ' default', 'default '] as $key) {
            try {
                $this->service()->renderCoverLetter($key, $this->letterContent());
                $this->fail(sprintf('cover letter template key [%s] should have been rejected.', $key));
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('Known templates: default', $e->getMessage());
            }
        }
    }

    public function test_each_document_kind_rejects_the_other_kinds_template_keys(): void
    {
        // The reason there are two whitelists rather than one merged map. The
        // template key is persisted next to a `type`, so a single namespace would
        // let `type = cover_letter` with a resume key resolve `latex::resume` and
        // render a resume into the cover-letter slot — a wrong document, no
        // exception, nothing to notice. Separate maps make that combination an
        // unknown key.
        //
        // Both directions currently share the key 'default', so the assertion is
        // on the *view names*: whichever key names a resume-only template must be
        // rejected by renderCoverLetter(), and vice versa. Today's cover-letter
        // map has no key the resume map lacks, so the resume direction is asserted
        // through the view namespace instead.
        View::spy();

        try {
            $this->service()->renderCoverLetter('latex::resume', $this->letterContent());
            $this->fail('a resume view name should never be accepted as a cover letter template key.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Unknown cover letter template', $e->getMessage());
        }

        try {
            $this->service()->renderResume('latex::cover-letter', $this->content());
            $this->fail('a cover letter view name should never be accepted as a resume template key.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Unknown resume template', $e->getMessage());
        }

        View::shouldNotHaveReceived('make');
    }

    public function test_the_two_whitelists_resolve_different_templates_for_the_same_key(): void
    {
        // The positive half of the separation: the shared 'default' key means
        // different things to the two entry points. If the maps were ever merged,
        // one of these two documents would come out as the other, and the previous
        // test would still pass.
        $this->stubSuccessfulEngine();

        $resume = $this->service()->renderResume('default', $this->content())->texSource;
        $letter = $this->service()->renderCoverLetter('default', $this->letterContent())->texSource;

        $this->assertStringContainsString('Dear Alex Reed,', $letter);
        $this->assertStringNotContainsString('Dear Alex Reed,', $resume);
        $this->assertNotSame($resume, $letter);
    }

    public function test_a_cover_letter_with_no_name_still_gets_a_kind_specific_filename(): void
    {
        $this->stubSuccessfulEngine();

        $content = $this->letterContent();
        unset($content['name'], $content['signature']);

        $document = $this->service()->renderCoverLetter('default', $content);

        $this->assertTrue($document->success);
        $this->assertStringContainsString('cover_letter.pdf', (string) $document->pdfPath);
        $this->assertStringNotContainsString('resume.pdf', (string) $document->pdfPath);
    }

    public function test_a_hostile_name_cannot_escape_the_render_directory(): void
    {
        $this->stubSuccessfulEngine();

        $content = $this->content();
        $content['name'] = '../../etc/cron.d/pwn';

        $document = $this->service()->renderResume('default', $content);

        $this->assertTrue($document->success);
        $this->assertStringStartsWith($this->tempRoot.'/work/', (string) $document->pdfPath);
        $this->assertStringNotContainsString('..', (string) $document->pdfPath);
    }
}

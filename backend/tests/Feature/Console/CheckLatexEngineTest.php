<?php

namespace Tests\Feature\Console;

use App\Exceptions\LatexEngineException;
use App\Services\Latex\LatexEngine;
use Tests\TestCase;

/**
 * Engine discovery and the `latex:check` diagnostic (Requirement 6.2).
 *
 * These tests must hold on a laptop with no TeX install and inside a container
 * that has tectonic, so nothing here asserts that the real engine exists. Where
 * a *present* binary is needed, `/bin/echo` stands in: the class only cares that
 * a path is an executable file, and using a stub keeps the suite from depending
 * on which machine it runs on.
 *
 * Validates: Requirements 6.2
 */
class CheckLatexEngineTest extends TestCase
{
    /** A path chosen so it cannot exist, for the "explicitly misconfigured" case. */
    private const MISSING_BINARY = '/nonexistent/path/to/tectonic-does-not-exist';

    /**
     * A real executable file on the host, or a skip.
     *
     * Not named run() — PHPUnit's TestCase::run() is final.
     */
    private function realExecutable(): string
    {
        if (! is_file('/bin/echo') || ! is_executable('/bin/echo')) {
            $this->markTestSkipped('No /bin/echo on this host to stand in for an installed engine.');
        }

        return '/bin/echo';
    }

    public function test_config_defaults_to_tectonic_resolved_from_path(): void
    {
        // tectonic by default because it is a single self-contained binary; null
        // binary means "resolve from PATH"; the timeout bounds one compile.
        $this->assertSame('tectonic', config('latex.engine'));
        $this->assertNull(config('latex.binary'));
        $this->assertSame(120, config('latex.timeout'));
    }

    public function test_missing_configured_binary_reports_unavailable_without_throwing(): void
    {
        config(['latex.binary' => self::MISSING_BINARY]);

        $engine = new LatexEngine();

        $this->assertFalse($engine->isAvailable());
        $this->assertNull($engine->binaryPath());
        // The diagnostic surface must stay usable when the thing it diagnoses is
        // absent: version() reports "no answer", it does not fail.
        $this->assertNull($engine->version());
    }

    public function test_ensure_available_throws_naming_the_engine_and_the_fallback(): void
    {
        config(['latex.engine' => 'tectonic', 'latex.binary' => self::MISSING_BINARY]);

        try {
            (new LatexEngine())->ensureAvailable();
            $this->fail('Expected LatexEngineException for a missing engine binary.');
        } catch (LatexEngineException $e) {
            $this->assertSame('tectonic', $e->engine);
            $this->assertSame(self::MISSING_BINARY, $e->binary);
            $this->assertStringContainsString('tectonic', $e->getMessage());
            $this->assertStringContainsString(self::MISSING_BINARY, $e->getMessage());
            // The message is what a deployer sees in a failed-job record, so it
            // has to carry the way out, not just the diagnosis.
            $this->assertStringContainsString('pdflatex', $e->getMessage());
            $this->assertStringContainsString('LATEX_BINARY', $e->getMessage());
        }
    }

    public function test_resolves_an_explicitly_configured_executable(): void
    {
        config(['latex.binary' => $this->realExecutable()]);

        $engine = new LatexEngine();

        $this->assertTrue($engine->isAvailable());
        $this->assertSame($this->realExecutable(), $engine->binaryPath());
    }

    public function test_command_fails_when_the_binary_is_missing(): void
    {
        config(['latex.engine' => 'tectonic', 'latex.binary' => self::MISSING_BINARY]);

        $this->artisan('latex:check')
            ->expectsOutputToContain('pdflatex')
            ->assertExitCode(1);
    }

    public function test_command_succeeds_when_the_binary_exists(): void
    {
        config(['latex.engine' => 'tectonic', 'latex.binary' => $this->realExecutable()]);

        $this->artisan('latex:check')
            ->expectsOutputToContain('is available')
            ->assertSuccessful();
    }
}

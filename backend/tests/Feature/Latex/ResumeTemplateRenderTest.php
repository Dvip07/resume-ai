<?php

namespace Tests\Feature\Latex;

use App\Services\Latex\LatexBladeCompiler;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * The `.tex.blade.php` template and its delimiter plumbing (Requirements 6.1,
 * 6.3).
 *
 * Render only: nothing here shells out to a TeX engine, so the suite passes on a
 * machine with no LaTeX install (which is the case for local dev — see
 * `latex:check`). What is asserted is the .tex *source*: that the custom
 * delimiters compile, that the document is structurally complete, and that every
 * injected value arrives escaped.
 */
class ResumeTemplateRenderTest extends TestCase
{
    /**
     * A profile-shaped payload matching what the render service will assemble
     * from UserProfile plus the tailoring model's JSON.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function resumeData(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Jane Doe',
            'headline' => 'Senior Backend Engineer',
            'contact' => ['jane@example.com', '+1 555 0100', 'Toronto, ON'],
            'summary' => 'Backend engineer focused on queue-driven Laravel systems.',
            'skills' => [
                ['label' => 'Core', 'items' => ['PHP', 'Laravel', 'PostgreSQL']],
                ['label' => 'Additional', 'items' => ['Docker']],
            ],
            'experience' => [
                [
                    'position' => 'Software Developer',
                    'company' => 'Example Corp',
                    'location' => 'Remote',
                    'dates' => '2021 - 2024',
                    'bullets' => [
                        'Cut build times in half',
                        'Owned the billing pipeline',
                    ],
                ],
            ],
            'education' => [
                [
                    'institution' => 'Example State University',
                    'degree' => 'B.S.',
                    'field' => 'Computer Science',
                    'location' => 'Springfield, IL',
                    'dates' => '2018 - 2022',
                ],
            ],
        ], $overrides);
    }

    private function render(array $overrides = []): string
    {
        return View::make('latex::resume', $this->resumeData($overrides))->render();
    }

    public function test_the_latex_view_namespace_resolves_the_tex_blade_extension(): void
    {
        // Proves the registration works end to end rather than assuming it: an
        // unregistered extension or namespace throws InvalidArgumentException
        // here, and a working one produces a document.
        $this->assertTrue(View::exists('latex::resume'));
        $this->assertStringContainsString('\documentclass', $this->render());
    }

    public function test_latex_templates_are_not_reachable_as_ordinary_web_views(): void
    {
        // The namespace is containment: a bare name must not resolve, so nothing
        // that turns a user-supplied string into a view name can reach a .tex
        // template.
        $this->assertFalse(View::exists('resume'));
    }

    public function test_it_renders_a_structurally_complete_document(): void
    {
        $tex = $this->render();

        $this->assertStringContainsString('\documentclass[11pt,letterpaper]{article}', $tex);
        $this->assertStringContainsString('\begin{document}', $tex);
        $this->assertStringContainsString('\end{document}', $tex);

        // Environments balance — an unclosed itemize is a compile failure.
        $this->assertSame(substr_count($tex, '\begin{itemize}'), substr_count($tex, '\end{itemize}'));
        $this->assertSame(substr_count($tex, '\begin{center}'), substr_count($tex, '\end{center}'));
    }

    public function test_it_places_the_supplied_content_under_the_expected_sections(): void
    {
        $tex = $this->render();

        $this->assertStringContainsString('Jane Doe', $tex);
        $this->assertStringContainsString('Senior Backend Engineer', $tex);
        $this->assertStringContainsString('jane@example.com', $tex);
        $this->assertStringContainsString('\section{Summary}', $tex);
        $this->assertStringContainsString('queue-driven Laravel systems', $tex);
        $this->assertStringContainsString('\section{Skills}', $tex);
        $this->assertStringContainsString('PostgreSQL', $tex);
        $this->assertStringContainsString('\section{Experience}', $tex);
        $this->assertStringContainsString('Example Corp', $tex);
        $this->assertStringContainsString('\item Cut build times in half', $tex);
        $this->assertStringContainsString('\section{Education}', $tex);
        $this->assertStringContainsString('Example State University', $tex);
    }

    public function test_it_preserves_the_model_supplied_bullet_order(): void
    {
        // Bullet order is the model's output (design.md: "ordered bullets per
        // experience entry"), so the template must not re-sort it.
        $tex = $this->render([
            'experience' => [[
                'position' => 'Engineer',
                'company' => 'Acme',
                'bullets' => ['first bullet', 'second bullet', 'third bullet'],
            ]],
        ]);

        $this->assertLessThan(strpos($tex, 'second bullet'), strpos($tex, 'first bullet'));
        $this->assertLessThan(strpos($tex, 'third bullet'), strpos($tex, 'second bullet'));
    }

    public function test_every_injected_value_is_latex_escaped(): void
    {
        $hostile = 'R&D 100% a_b #1 $x {y} \\input{/etc/passwd} ~ ^';

        $tex = $this->render([
            'name' => $hostile,
            'headline' => null,
            'contact' => [$hostile],
            'summary' => $hostile,
            'skills' => [['label' => $hostile, 'items' => [$hostile]]],
            'experience' => [[
                'position' => $hostile,
                'company' => $hostile,
                'location' => $hostile,
                'dates' => $hostile,
                'bullets' => [$hostile],
            ]],
            'education' => [[
                'institution' => $hostile,
                'degree' => $hostile,
                'field' => $hostile,
                'location' => $hostile,
                'dates' => $hostile,
            ]],
        ]);

        // The raw forms must appear nowhere: an unescaped \input is arbitrary
        // file inclusion, and an unescaped % silently truncates a line.
        $this->assertStringNotContainsString('\input{/etc/passwd}', $tex);
        $this->assertStringNotContainsString('R&D', $tex);
        $this->assertStringNotContainsString('100%', $tex);
        $this->assertStringNotContainsString('a_b', $tex);

        // `#` and `$` cannot be asserted by substring — `\#` contains `#` — so
        // check that no occurrence is left unpreceded by a backslash.
        $this->assertSame(0, preg_match('/(?<!\\\\)#/', $tex), 'found an unescaped # in the output');
        $this->assertSame(0, preg_match('/(?<!\\\\)\$/', $tex), 'found an unescaped $ in the output');

        // ...and the escaped forms must.
        $this->assertStringContainsString('R\&D 100\% a\_b \#1 \$x \{y\} \textbackslash{}input\{', $tex);
        $this->assertStringContainsString('\textasciitilde{}', $tex);
        $this->assertStringContainsString('\textasciicircum{}', $tex);
    }

    public function test_no_echo_in_the_template_bypasses_the_escaper(): void
    {
        // The structural counterpart to the test above (task 11.8).
        //
        // `test_every_injected_value_is_latex_escaped` proves the *documented*
        // keys are safe, which is a statement about today's template. The hole it
        // cannot see is a field added tomorrow: a new `<<! $value !>>` — or a
        // `<< >>` echo whose compiled form somehow skipped the echo format —
        // would leave that one field injectable while every existing assertion
        // stayed green, and nothing in the data contract would reveal it.
        //
        // So this asserts the property directly, on the compiled PHP: every echo
        // in the template routes through LatexEscaper, and the raw form is not
        // used at all. It is the only assertion here that keeps holding as the
        // template grows.
        $source = File::get(resource_path('views/latex/resume.tex.blade.php'));

        // Comment blocks are stripped first, and not as a convenience: the
        // template's own header block *documents* both echo forms, so a check
        // over the raw file would read the documentation as usage and would count
        // the illustrated `<< $value >>` as a real echo.
        $code = (string) preg_replace('/<<--.*?-->>/s', '', $source);

        $this->assertNotSame($source, $code, 'no comment block was stripped; the comment syntax has changed');

        $this->assertStringNotContainsString(
            '<<!',
            $code,
            'the template uses the raw, unescaped echo form; model and profile data must never reach it',
        );

        $compiled = app(LatexBladeCompiler::class)->compileString($code);

        // One `LatexEscaper::escape(` per `<< >>` echo. Counting rather than
        // pattern-matching for absence, because "no unescaped echo" is not
        // something a regex over compiled PHP can state — but a mismatch between
        // the two counts is exactly what an echo bypassing the format produces.
        $echoes = preg_match_all('/<<(?![-!])\s.+?>>/', $code);

        $this->assertGreaterThan(0, $echoes, 'the echo pattern matched nothing; it has drifted from the dialect');
        $this->assertSame(
            $echoes,
            substr_count($compiled, 'LatexEscaper::escape('),
            'the number of escaper calls in the compiled template does not match the number of echoes in it',
        );
    }

    public function test_html_escaping_is_not_applied(): void
    {
        // Stock Blade would emit &amp; and &#039; here. LaTeX output must not
        // inherit Blade's HTML echo format.
        $tex = $this->render(['summary' => "Ampersand & apostrophe ' and <angle>"]);

        $this->assertStringNotContainsString('&amp;', $tex);
        $this->assertStringNotContainsString('&#039;', $tex);
        $this->assertStringContainsString("apostrophe ' and <angle>", $tex);
    }

    public function test_an_empty_summary_omits_the_section_entirely(): void
    {
        foreach ([null, '', '   '] as $summary) {
            $tex = $this->render(['summary' => $summary]);

            $this->assertStringNotContainsString('\section{Summary}', $tex);
            $this->assertStringNotContainsString('\section{}', $tex);
        }
    }

    public function test_missing_education_and_skills_omit_their_sections(): void
    {
        $tex = $this->render([
            'education' => [],
            'skills' => [],
        ]);

        $this->assertStringNotContainsString('\section{Education}', $tex);
        $this->assertStringNotContainsString('\section{Skills}', $tex);
        $this->assertStringContainsString('\section{Experience}', $tex);
        $this->assertStringContainsString('\end{document}', $tex);
    }

    public function test_a_skill_group_of_only_blank_entries_is_dropped(): void
    {
        $tex = $this->render(['skills' => [['label' => 'Core', 'items' => ['', '  ']]]]);

        $this->assertStringNotContainsString('\section{Skills}', $tex);
        $this->assertStringNotContainsString('Core', $tex);
    }

    public function test_an_experience_entry_with_no_bullets_still_renders_validly(): void
    {
        $tex = $this->render([
            'experience' => [[
                'position' => 'Software Developer',
                'company' => 'Example Corp',
                'dates' => '2021 - 2024',
                'bullets' => [],
            ]],
        ]);

        $this->assertStringContainsString('\section{Experience}', $tex);
        $this->assertStringContainsString('Example Corp', $tex);
        // An itemize with no \item is a hard LaTeX error, so none may be opened.
        $this->assertStringNotContainsString('\begin{itemize}', $tex);
    }

    public function test_an_entirely_empty_payload_still_renders_a_compilable_shell(): void
    {
        $tex = View::make('latex::resume', [])->render();

        $this->assertStringContainsString('\begin{document}', $tex);
        $this->assertStringContainsString('\end{document}', $tex);
        $this->assertStringNotContainsString('\section{}', $tex);
        $this->assertStringNotContainsString('\begin{itemize}', $tex);
        $this->assertStringNotContainsString('\section{Experience}', $tex);
    }
}

<?php

namespace Tests\Feature\Latex;

use App\Services\Latex\LatexBladeCompiler;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * The base cover-letter template (Requirement 7.1).
 *
 * Render only, exactly as in {@see ResumeTemplateRenderTest}: nothing here shells
 * out to a TeX engine, so the suite passes on a machine with no LaTeX install
 * (which is the case for local dev — see `latex:check`). What is asserted is the
 * .tex *source*: that the custom delimiters compile, that the letter is
 * structurally complete, that every injected value arrives escaped, and that each
 * optional block degrades to nothing rather than to a stray command.
 */
class CoverLetterTemplateRenderTest extends TestCase
{
    /**
     * A payload matching what `renderCoverLetter()` (task 12.3) will assemble
     * from UserProfile, the listing, and the tailoring model's JSON.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function letterData(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Jane Doe',
            'contact' => ['jane@example.com', '+1 555 0100', 'Toronto, ON'],
            'date' => '17 September 2026',
            'recipient_name' => 'Alex Reed',
            'recipient_title' => 'Engineering Manager',
            'company' => 'Example Corp',
            'company_address' => ['100 King Street West', 'Toronto, ON M5X 1A9'],
            'job_title' => 'Senior Backend Engineer',
            'paragraphs' => [
                'I am writing to apply for the Senior Backend Engineer role.',
                'At Example Corp I would bring four years of queue-driven Laravel work.',
                'I would welcome the chance to talk further.',
            ],
            'closing' => 'Sincerely,',
            'signature' => 'Jane Doe',
        ], $overrides);
    }

    private function render(array $overrides = []): string
    {
        return View::make('latex::cover-letter', $this->letterData($overrides))->render();
    }

    public function test_the_latex_view_namespace_resolves_the_tex_blade_extension(): void
    {
        // Proves the registration picks up a *new* template with no extra wiring:
        // an unresolvable namespace or extension throws InvalidArgumentException
        // here, and a working one produces a document.
        $this->assertTrue(View::exists('latex::cover-letter'));
        $this->assertStringContainsString('\documentclass', $this->render());
    }

    public function test_latex_templates_are_not_reachable_as_ordinary_web_views(): void
    {
        // The namespace is containment: a bare name must not resolve, so nothing
        // that turns a user-supplied string into a view name can reach a .tex
        // template.
        $this->assertFalse(View::exists('cover-letter'));
    }

    public function test_it_renders_a_structurally_complete_document(): void
    {
        $tex = $this->render();

        $this->assertStringContainsString('\documentclass[11pt,letterpaper]{article}', $tex);
        $this->assertStringContainsString('\usepackage[margin=1in]{geometry}', $tex);
        $this->assertStringContainsString('\begin{document}', $tex);
        $this->assertStringContainsString('\end{document}', $tex);

        // One class, and no package the resume template has not already proven
        // available in tectonic's default bundle. A letter class would bring its
        // own \opening/\closing macros, which do not degrade quietly on an absent
        // field — the whole reason this is an `article`.
        // Counted at line start, so the preamble's own explanatory comments about
        // \usepackage are not mistaken for package loads.
        $this->assertSame(1, preg_match_all('/^\\\\documentclass/m', $tex));
        $this->assertSame(2, preg_match_all('/^\\\\usepackage/m', $tex));
        $this->assertStringContainsString('\usepackage[hidelinks]{hyperref}', $tex);

        // The dangling-`\\` hazard the template header documents: a `\\` with
        // nothing after it is a hard compile error, so the template uses none.
        $this->assertSame(0, preg_match('/\\\\\\\\\s*$/m', $tex), 'found a line-ending \\\\ in the output');
    }

    public function test_it_renders_every_block_of_the_business_letter(): void
    {
        $tex = $this->render();

        // Sender block.
        $this->assertStringContainsString('Jane Doe', $tex);
        $this->assertStringContainsString('jane@example.com', $tex);
        $this->assertStringContainsString('+1 555 0100', $tex);

        // Date, recipient, subject.
        $this->assertStringContainsString('17 September 2026', $tex);
        $this->assertStringContainsString('Alex Reed', $tex);
        $this->assertStringContainsString('Engineering Manager', $tex);
        $this->assertStringContainsString('Example Corp', $tex);
        $this->assertStringContainsString('100 King Street West', $tex);
        $this->assertStringContainsString('Re: Senior Backend Engineer at Example Corp', $tex);

        // Salutation, body, closing, signature.
        $this->assertStringContainsString('Dear Alex Reed,', $tex);
        $this->assertStringContainsString('queue-driven Laravel work', $tex);
        $this->assertStringContainsString('Sincerely,', $tex);
    }

    public function test_the_blocks_appear_in_business_letter_order(): void
    {
        $tex = $this->render();

        $positions = [
            'jane@example.com',
            '17 September 2026',
            'Alex Reed',
            'Re: Senior Backend Engineer',
            'I am writing to apply',
            'Sincerely,',
        ];

        foreach ($positions as $index => $needle) {
            if ($index === 0) {
                continue;
            }

            $this->assertLessThan(
                strpos($tex, $needle),
                strpos($tex, $positions[$index - 1]),
                sprintf('[%s] should precede [%s]', $positions[$index - 1], $needle),
            );
        }

        // The salutation names the recipient, so its second occurrence is the
        // greeting rather than the address line.
        $this->assertLessThan(strpos($tex, 'Dear Alex Reed'), strpos($tex, 'Example Corp'));
    }

    public function test_it_preserves_the_model_supplied_paragraph_order(): void
    {
        // The body is an ordered list from the model (design.md: structured
        // content, never raw LaTeX), so the template must not re-sort it.
        $tex = $this->render([
            'paragraphs' => ['first para', 'second para', 'third para'],
        ]);

        $this->assertLessThan(strpos($tex, 'second para'), strpos($tex, 'first para'));
        $this->assertLessThan(strpos($tex, 'third para'), strpos($tex, 'second para'));
    }

    public function test_every_injected_value_is_latex_escaped(): void
    {
        $hostile = 'R&D 100% a_b #1 $x {y} \\input{/etc/passwd} ~ ^';

        $tex = $this->render([
            'name' => $hostile,
            'contact' => [$hostile],
            'date' => $hostile,
            'recipient_name' => $hostile,
            'recipient_title' => $hostile,
            'company' => $hostile,
            'company_address' => [$hostile],
            'job_title' => $hostile,
            'paragraphs' => [$hostile],
            'closing' => $hostile,
            'signature' => $hostile,
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
        // The structural counterpart to the test above, and the only assertion
        // here that keeps holding as the template grows.
        //
        // `test_every_injected_value_is_latex_escaped` proves the *documented*
        // keys are safe, which is a statement about today's template. The hole it
        // cannot see is a field added tomorrow: a new `<<! $value !>>` — or a
        // `<< >>` echo whose compiled form somehow skipped the echo format —
        // would leave that one field injectable while every existing assertion
        // stayed green.
        $source = File::get(resource_path('views/latex/cover-letter.tex.blade.php'));

        // Comment blocks are stripped first, and not as a convenience: the
        // template's own header block *documents* both echo forms, so a check over
        // the raw file would read the documentation as usage.
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
        $tex = $this->render(['paragraphs' => ["Ampersand & apostrophe ' and <angle>"]]);

        $this->assertStringNotContainsString('&amp;', $tex);
        $this->assertStringNotContainsString('&#039;', $tex);
        $this->assertStringContainsString("apostrophe ' and <angle>", $tex);
    }

    public function test_an_unknown_recipient_falls_back_to_a_generic_salutation(): void
    {
        foreach ([null, '', '   '] as $recipient) {
            $tex = $this->render(['recipient_name' => $recipient, 'recipient_title' => null]);

            $this->assertStringContainsString('Dear Hiring Manager,', $tex);
            // No "Dear ," and no empty group left behind by the missing name.
            $this->assertSame(0, preg_match('/Dear\s*,/', $tex), 'found an empty salutation');
            $this->assertStringNotContainsString('Alex Reed', $tex);
        }
    }

    public function test_a_missing_company_address_still_renders_the_recipient_block(): void
    {
        $tex = $this->render(['company_address' => []]);

        $this->assertStringContainsString('Alex Reed', $tex);
        $this->assertStringContainsString('Example Corp', $tex);
        $this->assertStringNotContainsString('100 King Street West', $tex);
        $this->assertStringContainsString('\end{document}', $tex);
    }

    public function test_a_missing_date_omits_the_date_line(): void
    {
        foreach ([null, '', '  '] as $date) {
            $tex = $this->render(['date' => $date]);

            $this->assertStringNotContainsString('17 September 2026', $tex);
            $this->assertStringContainsString('Dear Alex Reed,', $tex);
            $this->assertStringContainsString('\end{document}', $tex);
        }
    }

    public function test_a_missing_job_title_and_company_omit_the_subject_line(): void
    {
        $tex = $this->render([
            'job_title' => null,
            'company' => null,
            'company_address' => [],
        ]);

        $this->assertStringNotContainsString('Re:', $tex);
        $this->assertStringNotContainsString('\textbf{}', $tex);
        $this->assertStringContainsString('Dear Alex Reed,', $tex);
    }

    public function test_a_known_company_with_no_job_title_still_names_the_company(): void
    {
        $tex = $this->render(['job_title' => null]);

        $this->assertStringContainsString('Re: Application to Example Corp', $tex);
    }

    public function test_an_empty_paragraph_list_still_renders_a_valid_letter(): void
    {
        foreach ([[], ['', '   ']] as $paragraphs) {
            $tex = $this->render(['paragraphs' => $paragraphs]);

            $this->assertStringContainsString('Dear Alex Reed,', $tex);
            $this->assertStringContainsString('Sincerely,', $tex);
            $this->assertStringContainsString('\end{document}', $tex);
            $this->assertStringNotContainsString('\textbf{}', $tex);
        }
    }

    public function test_the_signature_falls_back_to_the_candidate_name(): void
    {
        $tex = $this->render(['signature' => null, 'closing' => null]);

        $this->assertStringContainsString('Sincerely,', $tex);

        // The signature is the name again, below the closing — asserted
        // positionally rather than by count, since the name also appears in the
        // sender block and twice in the PDF metadata.
        $body = substr($tex, (int) strpos($tex, 'Sincerely,'));
        $this->assertStringContainsString('Jane Doe', $body);
    }

    public function test_an_entirely_empty_payload_still_renders_a_compilable_shell(): void
    {
        $tex = View::make('latex::cover-letter', [])->render();

        $this->assertStringContainsString('\begin{document}', $tex);
        $this->assertStringContainsString('\end{document}', $tex);
        $this->assertStringContainsString('Dear Hiring Manager,', $tex);
        $this->assertStringContainsString('Sincerely,', $tex);

        // Nothing structural is left with an empty argument or a dangling break.
        $this->assertStringNotContainsString('\textbf{}', $tex);
        $this->assertStringNotContainsString('Re:', $tex);
        $this->assertSame(0, preg_match('/\\\\\\\\\s*$/m', $tex), 'found a line-ending \\\\ in the output');
    }
}

<?php

namespace Tests\Unit\Services\Latex;

use App\Services\Latex\LatexEscaper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The escaping contract behind Requirement 6.3.
 *
 * Plain PHPUnit TestCase — the escaper touches no framework services, and
 * booting Laravel for a pure string function only slows the suite down.
 */
class LatexEscaperTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function specialCharacters(): array
    {
        return [
            'backslash' => ['\\', '\textbackslash{}'],
            'open brace' => ['{', '\{'],
            'close brace' => ['}', '\}'],
            'dollar' => ['$', '\$'],
            'ampersand' => ['&', '\&'],
            'hash' => ['#', '\#'],
            'caret' => ['^', '\textasciicircum{}'],
            'underscore' => ['_', '\_'],
            'percent' => ['%', '\%'],
            'tilde' => ['~', '\textasciitilde{}'],
        ];
    }

    #[DataProvider('specialCharacters')]
    public function test_it_escapes_each_latex_special_character(string $input, string $expected): void
    {
        $this->assertSame($expected, LatexEscaper::escape($input));
    }

    /**
     * The ordering guarantee: backslash is substituted first and its output is
     * never rescanned. If it were, `&` -> `\&` would become
     * `\textbackslash{}&` on a second pass.
     */
    public function test_it_does_not_double_escape_introduced_backslashes(): void
    {
        $this->assertSame('\&', LatexEscaper::escape('&'));
        $this->assertSame('\%\&\_', LatexEscaper::escape('%&_'));
        $this->assertStringNotContainsString('\textbackslash', LatexEscaper::escape('& % _ # $'));
    }

    public function test_escaping_is_not_idempotent_by_accident(): void
    {
        // Escaping already-escaped text must escape again — proof the function is
        // a plain substitution and not silently "smart", which is what makes a
        // single central call site (the template echo) the correct design.
        $once = LatexEscaper::escape('50%');
        $twice = LatexEscaper::escape($once);

        $this->assertSame('50\%', $once);
        $this->assertSame('50\textbackslash{}\%', $twice);
    }

    public function test_it_escapes_a_realistic_injection_attempt(): void
    {
        $escaped = LatexEscaper::escape('\input{/etc/passwd} & 100% \write18{rm -rf /}');

        $this->assertStringNotContainsString('\input{', $escaped);
        $this->assertStringNotContainsString('\write18{', $escaped);
        $this->assertStringContainsString('\textbackslash{}input\{', $escaped);
        $this->assertStringContainsString('100\%', $escaped);
    }

    public function test_it_leaves_ordinary_text_untouched(): void
    {
        $text = 'Senior Backend Engineer, Acme (2021-2024) - Toronto';

        $this->assertSame($text, LatexEscaper::escape($text));
    }

    public function test_it_collapses_absent_values_to_an_empty_string(): void
    {
        $this->assertSame('', LatexEscaper::escape(null));
        $this->assertSame('', LatexEscaper::escape(''));
        $this->assertSame('', LatexEscaper::escape(false));
        $this->assertSame('', LatexEscaper::escape(['not', 'stringable']));
    }

    public function test_it_stringifies_numbers_and_stringables(): void
    {
        $this->assertSame('24', LatexEscaper::escape(24));
        $this->assertSame('3.5', LatexEscaper::escape(3.5));

        $stringable = new class implements \Stringable
        {
            public function __toString(): string
            {
                return 'R&D';
            }
        };

        $this->assertSame('R\&D', LatexEscaper::escape($stringable));
    }
}

<?php

namespace Tests\Unit\Services\Latex;

use App\Services\Latex\LatexEscaper;
use PHPUnit\Framework\TestCase;

/**
 * The *totality* of the escaping contract behind Requirement 6.3 (task 11.8).
 *
 * {@see LatexEscaperTest} asserts the escaper against a hand-listed sample: ten
 * named characters, a couple of injection strings, a few absent values. That is
 * the right shape for documenting the contract and the wrong shape for trusting
 * it, because every assertion there is a character somebody remembered. The
 * interesting question is the one a sample cannot answer: *is the special set
 * complete, and does anything at all survive unescaped?*
 *
 * So this file asserts properties rather than examples:
 *
 *  1. The set of characters the escaper rewrites is derived by sweeping the ASCII
 *     range, not listed — a special character added to LaTeX's set and forgotten
 *     in {@see LatexEscaper::REPLACEMENTS}, or a replacement deleted in a
 *     refactor, changes the swept set and fails here.
 *  2. For a few hundred generated strings drawn from an alphabet that is mostly
 *     special characters, the output contains no unescaped special once the
 *     known escape sequences are removed. This is the property that matters:
 *     "no residue" is what makes a document compile and makes `\input` inert.
 *  3. Escaping round-trips. Applying the inverse substitution recovers the input
 *     byte for byte, which proves the mapping is injective — an escaper that
 *     merely *deleted* dangerous characters would satisfy property 2 and
 *     silently drop content from somebody's resume.
 *
 * Deterministic by construction: the generator is seeded with a fixed value, so
 * a failure here reproduces exactly rather than appearing on one CI run in ten.
 * There is no property-testing library in this project's dependencies and one
 * is not worth adding for a pure string function — a seeded loop is the same
 * idea without the shrinking.
 */
class LatexEscaperPropertyTest extends TestCase
{
    /**
     * The characters LaTeX gives special meaning to in text mode, as a set this
     * file *checks* rather than trusts. Kept here, independently of the escaper's
     * own constant, so agreement between the two is an assertion and not a
     * tautology.
     *
     * @var list<string>
     */
    private const SPECIALS = ['\\', '{', '}', '$', '&', '#', '^', '_', '%', '~'];

    /**
     * The inverse of {@see LatexEscaper::escape()}'s substitution.
     *
     * Correct under a single `strtr()` pass for the same reason the forward
     * direction is: `strtr()` with one array matches the *longest* key at each
     * position and never rescans its own output. `\textbackslash{}` is therefore
     * consumed whole rather than being read as a backslash followed by `\{`, and
     * an escaped `\&` that came from a literal `\&` in the input decodes to
     * exactly that.
     *
     * @var array<string, string>
     */
    private const INVERSE = [
        '\textbackslash{}' => '\\',
        '\textasciicircum{}' => '^',
        '\textasciitilde{}' => '~',
        '\{' => '{',
        '\}' => '}',
        '\$' => '$',
        '\&' => '&',
        '\#' => '#',
        '\_' => '_',
        '\%' => '%',
    ];

    /**
     * Strings built mostly out of the characters that break LaTeX, plus the
     * multibyte and punctuation noise real resume text carries.
     *
     * The alphabet is weighted towards specials on purpose — random prose would
     * spend the whole budget asserting that letters pass through — and includes
     * multi-character tokens (`\input{`, `%%`) so adjacency and overlap get
     * exercised, which is where an implementation built from chained
     * `str_replace()` calls would fail.
     *
     * @return iterable<int, string>
     */
    private static function generatedStrings(int $count = 400): iterable
    {
        // Fixed seed: every run sees the same 400 strings, so a failure is
        // reproducible and a green run is not luck.
        mt_srand(20250917);

        $alphabet = [
            '\\', '{', '}', '$', '&', '#', '^', '_', '%', '~',
            '\\', '%', '&', '{', '}',
            'a', 'Q', '7', ' ', '-', ',', '.', "'",
            'é', 'ü', 'ß', '日', '’', '—', '🙂',
            '\input{', '\write18{', '%%', '&&', '${', '~^', 'R&D', '100%',
        ];
        $last = count($alphabet) - 1;

        for ($i = 0; $i < $count; $i++) {
            $string = '';

            for ($piece = 0, $pieces = mt_rand(1, 20); $piece < $pieces; $piece++) {
                $string .= $alphabet[mt_rand(0, $last)];
            }

            yield $string;
        }
    }

    /** What is left of an escaped string once every known escape sequence is removed. */
    private function residue(string $escaped): string
    {
        return strtr($escaped, array_fill_keys(array_keys(self::INVERSE), ''));
    }

    public function test_the_set_of_rewritten_characters_is_exactly_the_latex_special_set(): void
    {
        $rewritten = [];

        // The whole single-byte ASCII range, control characters included: a
        // sweep, so the answer does not depend on which characters this test
        // thought to mention.
        for ($code = 0; $code < 128; $code++) {
            $character = chr($code);

            if (LatexEscaper::escape($character) !== $character) {
                $rewritten[] = $character;
            }
        }

        sort($rewritten);
        $expected = self::SPECIALS;
        sort($expected);

        $this->assertSame(
            $expected,
            $rewritten,
            'the escaper rewrites a different set of characters than the documented LaTeX special set',
        );
    }

    public function test_every_special_character_escapes_to_something_that_is_itself_safe(): void
    {
        // The fixed point of the property below, stated on its own: no
        // replacement may reintroduce an unescaped special of its own. `\%`
        // legitimately contains a backslash, so the check is that the *residue*
        // after removing recognised sequences is empty — not that the output
        // contains no specials.
        foreach (self::SPECIALS as $special) {
            $this->assertSame(
                '',
                $this->residue(LatexEscaper::escape($special)),
                sprintf('escaping [%s] left an unrecognised remainder', $special),
            );
        }
    }

    public function test_no_unescaped_special_survives_for_any_generated_input(): void
    {
        foreach (self::generatedStrings() as $input) {
            $residue = $this->residue(LatexEscaper::escape($input));

            $this->assertSame(
                0,
                preg_match('/[\\\\{}$&#^_~%]/', $residue),
                sprintf(
                    "an unescaped LaTeX special survived.\n  input:   %s\n  escaped: %s\n  residue: %s",
                    $input,
                    LatexEscaper::escape($input),
                    $residue,
                ),
            );
        }
    }

    public function test_escaping_is_reversible_so_no_content_is_silently_dropped(): void
    {
        foreach (self::generatedStrings() as $input) {
            $this->assertSame(
                $input,
                strtr(LatexEscaper::escape($input), self::INVERSE),
                sprintf('escaping [%s] was not reversible, so it lost or altered content', $input),
            );
        }
    }

    public function test_generated_output_is_always_valid_utf8(): void
    {
        // The escaper substitutes on bytes, and the specials are all ASCII, so
        // this holds — but it holds *because* of that, and a future change to a
        // byte-oriented regex or a `substr` truncation could split a multibyte
        // sequence and produce a .tex file the engine rejects outright.
        foreach (self::generatedStrings() as $input) {
            $this->assertTrue(
                mb_check_encoding(LatexEscaper::escape($input), 'UTF-8'),
                sprintf('escaping [%s] produced invalid UTF-8', $input),
            );
        }
    }

    public function test_multibyte_text_passes_through_untouched(): void
    {
        // Accented Latin, CJK, an em dash, a combining acute, an emoji: all
        // legitimate in a name, a company or a bullet, and none of them special
        // to LaTeX. A tilde-hunting implementation that matched on bytes without
        // care could corrupt any of these.
        $text = "Zürich — 日本語 e\u{0301} naïve Ærø 🙂 O’Reilly";

        $this->assertSame($text, LatexEscaper::escape($text));
    }

    public function test_multibyte_text_mixed_with_specials_escapes_only_the_specials(): void
    {
        $this->assertSame('Café \& Bär 100\%', LatexEscaper::escape('Café & Bär 100%'));
        $this->assertSame('日本語\_タグ', LatexEscaper::escape('日本語_タグ'));
        // Both characters of an already-escaped `\&` are escaped in turn, which
        // is the non-idempotence LatexEscaperTest pins: content is content, even
        // when it looks like markup.
        $this->assertSame('O’Reilly \textbackslash{}\& Co', LatexEscaper::escape('O’Reilly \& Co'));
    }
}

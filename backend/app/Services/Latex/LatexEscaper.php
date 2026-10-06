<?php

namespace App\Services\Latex;

/**
 * The single place where a value coming from outside the template — profile
 * fields, a job title, model-generated bullets — is made safe to drop into a
 * LaTeX document (Requirement 6.3).
 *
 * Why this exists at all: the tailoring pipeline never lets the model emit raw
 * LaTeX (design.md, `LatexRenderService`). The model returns structured JSON and
 * the template does the typesetting, which means every string it hands us is
 * *content*, and content containing `&`, `%`, `_` or `\` is not a formatting
 * choice — it is either a compile error or, with `\input`/`\write18`, an
 * injection. Escaping centrally is the only way that holds: an escape applied
 * per call site is an escape someone eventually forgets.
 *
 * This class is wired in as the echo format of the LaTeX Blade compiler
 * ({@see LatexBladeCompiler}), so a `<< $value >>` in a `.tex.blade.php`
 * template routes through {@see escape()} automatically. Templates do not opt
 * in; they would have to explicitly opt *out*.
 */
class LatexEscaper
{
    /**
     * Character-for-character replacements, in the order they must be applied.
     *
     * Ordering is the whole correctness argument here. Every replacement below
     * emits a backslash, so `\` must be substituted first — otherwise the
     * backslashes introduced by escaping `&`, `%`, `#`, `$` and `_` would
     * themselves be escaped on a later pass and the output would be
     * double-escaped garbage (`&` -> `\&` -> `\textbackslash{}&`). PHP preserves
     * array insertion order and strtr() with a single array replaces each source
     * position once, longest-match-first, without rescanning its own output —
     * which is exactly the guarantee this needs, and is why this is one strtr()
     * call rather than a chain of str_replace().
     *
     * On the individual choices:
     *  - `\` becomes `\textbackslash{}` rather than `$\backslash$`, so it does
     *    not flip math mode on and off around itself. The trailing `{}` stops
     *    LaTeX from swallowing a following space as the command's argument
     *    delimiter.
     *  - `^` and `~` are *active* characters (superscript, non-breaking space),
     *    so `\^` and `\~` alone are accents looking for a letter to sit on.
     *    `\textasciicircum{}`/`\textasciitilde{}` are the standalone glyphs.
     *  - `%` must be escaped or the rest of the line becomes a comment — the
     *    quietest of these failures, because it silently deletes content
     *    instead of erroring.
     *  - `{`/`}` are escaped even though they are balanced in the source string,
     *    because an unbalanced brace from user data would otherwise consume the
     *    template's own grouping.
     */
    private const REPLACEMENTS = [
        '\\' => '\textbackslash{}',
        '{' => '\{',
        '}' => '\}',
        '$' => '\$',
        '&' => '\&',
        '#' => '\#',
        '^' => '\textasciicircum{}',
        '_' => '\_',
        '%' => '\%',
        '~' => '\textasciitilde{}',
    ];

    /**
     * Escape a value for use in LaTeX text.
     *
     * Accepts anything the template might echo. Null and false collapse to the
     * empty string so an absent profile field renders as nothing rather than the
     * word "null" or a PHP deprecation; ints, floats and Stringables (a
     * `Carbon` date, an `HtmlString`) are stringified first. Anything genuinely
     * un-stringifiable — an array, an object with no `__toString` — is a
     * template bug, not user input, and returns empty rather than fataling
     * mid-document: a render is worth more than a stack trace here, and the
     * missing text is visible in review.
     */
    public static function escape(mixed $value): string
    {
        $string = self::stringify($value);

        if ($string === '') {
            return '';
        }

        return strtr($string, self::REPLACEMENTS);
    }

    private static function stringify(mixed $value): string
    {
        return match (true) {
            $value === null, $value === false => '',
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            $value === true => '1',
            $value instanceof \Stringable => (string) $value,
            is_object($value) && method_exists($value, '__toString') => (string) $value,
            default => '',
        };
    }
}

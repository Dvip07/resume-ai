<?php

namespace App\Services\Latex;

use Illuminate\View\Compilers\BladeCompiler;

/**
 * A Blade compiler that speaks a delimiter set LaTeX does not use, so resume and
 * cover-letter templates can be written as real `.tex` files (Requirement 6.1).
 *
 * ## Why a subclass, and not one of the alternatives
 *
 * The collision is not incidental: `{` and `}` are LaTeX's grouping syntax and
 * appear in nearly every line of a document, so Blade's `{{ }}`, `{!! !!}` and
 * `{{{ }}}` are all live minefields. `\textbf{{\large x}}` is ordinary LaTeX and
 * a Blade echo at the same time.
 *
 * Three options were on the table:
 *
 *  1. Keep default Blade and write `@{{` everywhere a literal brace pair
 *     appears. Rejected: it makes the template stop looking like LaTeX, so it
 *     can no longer be copy-pasted into a TeX editor to debug, and it fails
 *     *silently* — a forgotten escape compiles to a PHP echo of an undefined
 *     expression rather than to an error.
 *  2. Mutate the shared `blade.compiler` singleton's tags. Rejected outright:
 *     the application's web views are Blade too, and changing delimiters
 *     globally would break every one of them.
 *  3. This — a second compiler with its own tags, bound to its own view engine,
 *     reached only through the `.tex.blade.php` extension. The tag properties
 *     are `protected` with no setters in Laravel 12 (the old
 *     `setContentTags()`/`setEscapedTags()` were removed), so a subclass is the
 *     supported way to change them, and scoping by extension means default
 *     Blade is untouched for `.blade.php` views.
 *
 * ## The delimiters
 *
 * | purpose                        | tag              |
 * |--------------------------------|------------------|
 * | escaped echo (use this)        | `<< $value >>`   |
 * | raw echo, no escaping          | `<<! $tex !>>`   |
 * | legacy triple-escape           | `<<< $value >>>` |
 *
 * `<`/`>` carry no meaning in LaTeX text mode — they typeset as inverted
 * exclamation/question marks in the default font, which is a rendering quirk of
 * literal angle brackets, not a syntax conflict, and the templates here use
 * `\textless`/`\textgreater` if they ever need the glyphs. All three tokens are
 * regex-safe as written, which matters because Blade interpolates these directly
 * into patterns without quoting them.
 *
 * `@`-directives (`@if`, `@foreach`) are deliberately left alone. They are not a
 * conflict in practice: `@` is only special to LaTeX between `\makeatletter` and
 * `\makeatother`, which no template here uses. A literal `@` in template *text*
 * still needs doubling as `@@`; a literal `@` arriving in *data* is untouched,
 * since runtime values are never re-compiled.
 *
 * ## Escaping is the default, not an option
 *
 * The echo format is repointed from Blade's HTML-oriented `e()` to
 * {@see LatexEscaper::escape()}. This is the load-bearing part of Requirement
 * 6.3: `e()` would produce `&amp;` and `&#039;` — valid HTML, broken LaTeX —
 * while leaving `\`, `%` and `_` untouched, which is precisely backwards for
 * this output format. With the format swapped, the natural thing to write in a
 * template (`<< $value >>`) is also the safe thing, and emitting unescaped
 * content requires reaching for the visibly different `<<! !>>`.
 */
class LatexBladeCompiler extends BladeCompiler
{
    /** `<<! ... !>>` — unescaped. Reserved for LaTeX we generated ourselves. */
    protected $rawTags = ['<<!', '!>>'];

    /** `<< ... >>` — the normal echo, escaped via {@see $echoFormat}. */
    protected $contentTags = ['<<', '>>'];

    /**
     * `<<< ... >>>` — Blade's legacy always-escaped form.
     *
     * Overridden not because templates should use it, but because leaving it at
     * `{{{ }}}` would reintroduce the exact brace collision this class exists to
     * remove: `\frac{{{a}}}{b}` would compile as an echo.
     */
    protected $escapedTags = ['<<<', '>>>'];

    /**
     * Every `<< >>` echo is routed through the LaTeX escaper.
     *
     * Fully qualified because this string is written verbatim into compiled PHP,
     * which has no `use` statements from this file.
     */
    protected $echoFormat = '\App\Services\Latex\LatexEscaper::escape(%s)';
}

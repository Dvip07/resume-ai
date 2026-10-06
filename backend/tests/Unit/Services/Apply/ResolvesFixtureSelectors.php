<?php

namespace Tests\Unit\Services\Apply;

use DOMDocument;
use DOMXPath;
use Symfony\Component\CssSelector\CssSelectorConverter;

/**
 * Resolves an adapter's configured CSS selectors against a recorded fixture DOM.
 *
 * The worker-level adapter tests fake the automation worker, and the fake
 * answers `ok` to whatever step it was handed — so a selector typo in
 * `config/apply.php` passes all of them and fails only in production, as a step
 * timeout against a real form. This is the piece that closes that gap: load the
 * hand-authored markup from `tests/Fixtures/Apply`, run the selector, count what
 * came back.
 *
 * Selectors in config are comma-joined alternatives (Playwright acts on the
 * first match). `Symfony\Component\CssSelector` translates a selector *group*
 * straight into a unioned XPath expression, and an XPath node-set is distinct by
 * definition, so two alternatives that name the same element count once — which
 * is what makes "exactly one element" a usable assertion. The component is
 * already installed as a dependency of `symfony/browser-kit` (a direct require)
 * and `symfony/dom-crawler`, so nothing was added to composer.json for this.
 */
trait ResolvesFixtureSelectors
{
    /** @var array<string, DOMXPath> */
    private array $fixtureDocuments = [];

    private ?CssSelectorConverter $cssConverter = null;

    private function fixturePath(string $ats, string $page): string
    {
        return base_path('tests/Fixtures/Apply/'.$ats.'/'.$page.'.html');
    }

    private function fixtureHtml(string $ats, string $page): string
    {
        $path = $this->fixturePath($ats, $page);

        $this->assertFileExists($path, "Missing apply fixture: {$ats}/{$page}.html");

        return (string) file_get_contents($path);
    }

    private function fixtureDom(string $ats, string $page): DOMXPath
    {
        $key = $ats.'/'.$page;

        if (! isset($this->fixtureDocuments[$key])) {
            $document = new DOMDocument;
            // The fixtures are hand-authored but still parsed leniently, the
            // same way a browser would: a warning about an unknown attribute
            // must not be the thing that fails a selector assertion.
            $previous = libxml_use_internal_errors(true);
            $document->loadHTML($this->fixtureHtml($ats, $page));
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            $this->fixtureDocuments[$key] = new DOMXPath($document);
        }

        return $this->fixtureDocuments[$key];
    }

    /** Elements in the fixture the selector (or any of its alternatives) matches. */
    private function matchCount(string $ats, string $page, string $selector): int
    {
        $this->cssConverter ??= new CssSelectorConverter;

        $nodes = $this->fixtureDom($ats, $page)->query($this->cssConverter->toXPath($selector));

        return $nodes === false ? 0 : $nodes->length;
    }

    /**
     * A field, upload, click or submit target: one selector, one element.
     *
     * Anything else is selector rot in the making — zero means the step will
     * time out in production, more than one means the worker's "first match"
     * decides which element gets typed into.
     */
    private function assertResolvesToOneElement(string $ats, string $page, string $name, string $selector): void
    {
        $this->assertSame(
            1,
            $this->matchCount($ats, $page, $selector),
            "apply.adapters.{$ats}.{$name} should match exactly one element in {$ats}/{$page}.html: {$selector}"
        );
    }

    /**
     * A page-scope selector: `body`, a posting wrapper, a confirmation banner.
     *
     * These deliberately end in a broad fallback (`body`, `.content-wrapper h2`)
     * so wall detection and confirmation evidence still work on a page whose
     * chrome moved, so more than one match is correct here. Zero is not.
     */
    private function assertResolvesToSomething(string $ats, string $page, string $name, string $selector): void
    {
        $this->assertGreaterThan(
            0,
            $this->matchCount($ats, $page, $selector),
            "apply.adapters.{$ats}.{$name} should match at least one element in {$ats}/{$page}.html: {$selector}"
        );
    }

    /** The page `<title>`, which is the scope wall detection matches titles against. */
    private function fixtureTitle(string $ats, string $page): string
    {
        $nodes = $this->fixtureDom($ats, $page)->query('//title');

        return $nodes === false || $nodes->length === 0 ? '' : trim((string) $nodes->item(0)->textContent);
    }

    /** The text a `readText` on `body` would read back from the fixture. */
    private function fixtureText(string $ats, string $page): string
    {
        $nodes = $this->fixtureDom($ats, $page)->query('//body');

        if ($nodes === false || $nodes->length === 0) {
            return '';
        }

        return trim((string) preg_replace('/\s+/', ' ', (string) $nodes->item(0)->textContent));
    }
}

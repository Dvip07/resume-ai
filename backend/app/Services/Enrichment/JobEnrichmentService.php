<?php

namespace App\Services\Enrichment;

use App\Enums\EnrichmentOutcome;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\CssSelector\Exception\SyntaxErrorException;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

/**
 * Fetches a job posting's full description from its `application_url` over plain
 * HTTP and extracts it from the page's DOM (Requirement 3.4a, task 9.1).
 *
 * This is the *first* and cheapest of the two enrichment paths design.md calls
 * for. Most postings — every ATS-hosted one, most company career pages — are
 * server-rendered, so an ordinary GET plus a selector list gets the whole JD
 * without a browser process, a driver binary, or the bot-detection surface that
 * comes with driving Chrome. The headless-browser fallback (task 9.2) is
 * reserved for the {@see \App\Enums\EnrichmentOutcome::NoContent} case this
 * service reports, and nothing here knows about it.
 *
 * Three things distinguish it from the code it replaces
 * (`JobListingController::scrapeAndUpdateJobDescription`, the
 * `jobs:update-descriptions` command and `App\Jobs\UpdateJobDescription`, all
 * consolidated in task 9.3):
 *
 *  - **The selector list is configuration, not code.** `config/enrichment.php`
 *    owns it, so adding support for another ATS is a config line rather than an
 *    edit to a controller method, and the same list serves the browser fallback.
 *  - **robots.txt is checked first** ({@see RobotsTxtGate}), per Requirement
 *    3.4c. The legacy code fetched unconditionally while spoofing a desktop
 *    Chrome user agent.
 *  - **It returns a result instead of writing to the database.** Persistence,
 *    `pipeline_stage` transitions and retries belong to the queued job; this
 *    class is pure enough to test against fixture HTML.
 *
 * Extraction never falls back to the raw page source the way the legacy scraper
 * did. Whole-page HTML is not a job description: it scores badly, costs a large
 * multiple of the tokens, and hides the failure instead of reporting it.
 *
 * Task 9.2 added the second path. When — and only when — the plain fetch reports
 * {@see \App\Enums\EnrichmentOutcome::NoContent}, the URL is re-rendered by the
 * Node/Playwright worker ({@see AutomationWorkerClient}) and the *same*
 * extraction runs over the post-JavaScript HTML. Keeping the selector list, the
 * length floor and the bot-wall markers on this side is what stops the two paths
 * from drifting into disagreeing about what a description is. A worker that is
 * disabled (the default), unconfigured or unreachable costs nothing: the
 * plain-fetch outcome stands and the posting is retried later.
 */
class JobEnrichmentService
{
    public function __construct(
        private readonly RobotsTxtGate $robots,
        private readonly AutomationWorkerClient $browser,
    ) {
    }

    /**
     * Attempt to retrieve the description at `$url`.
     *
     * Never throws for an expected condition — an unfetchable page, a bot wall
     * and a JS-rendered posting are all normal and are reported as outcomes, so
     * one bad posting cannot fail a batch.
     */
    public function fetchDescription(?string $url): EnrichmentResult
    {
        $url = is_string($url) ? trim($url) : '';

        if (! $this->isFetchableUrl($url)) {
            return EnrichmentResult::invalidUrl('Not an absolute http(s) URL: '.($url === '' ? '(empty)' : $url));
        }

        if (! $this->robots->allows($url)) {
            $reason = $this->robots->reasonFor($url);
            Log::info('Skipped enrichment for a robots.txt-disallowed URL.', ['url' => $url]);

            return EnrichmentResult::robotsDisallowed($reason);
        }

        try {
            $response = $this->get($url);
        } catch (Throwable $e) {
            return EnrichmentResult::fetchFailed($e->getMessage());
        }

        $result = $this->extractFrom($response, $url);

        if (! $result->outcome->warrantsBrowserFallback()) {
            return $result;
        }

        return $this->renderInBrowser($url, $result);
    }

    /**
     * Second enrichment path: re-render a client-side posting in the automation
     * worker and re-run extraction on the result (Requirement 3.4b, task 9.2).
     *
     * Reached only from a {@see EnrichmentOutcome::NoContent} plain fetch. The
     * other outcomes are excluded by design and each for its own reason: a
     * robots.txt refusal is about the crawler and not the transport, a bot wall
     * meets the same wall in Chrome, an invalid URL has nothing to render, and a
     * transient transport failure deserves the cheap retry rather than the
     * expensive one.
     *
     * @param EnrichmentResult $plain What the plain fetch produced, returned
     *                                unchanged whenever the fallback cannot
     *                                improve on it.
     */
    protected function renderInBrowser(string $url, EnrichmentResult $plain): EnrichmentResult
    {
        if (! $this->browser->isConfigured()) {
            // The default state, not a problem: log at debug so a dev running
            // without the worker isn't told off once per posting.
            Log::debug('Browser fallback is not enabled; keeping the plain-fetch outcome.', [
                'url' => $url,
            ]);

            return $plain;
        }

        // The selector the plain fetch got closest with, as a render hint. Null
        // when nothing matched at all, which is the common case here.
        $page = $this->browser->render($url, $plain->selector);

        if ($page === null) {
            return $plain;
        }

        $rendered = $this->extractFromHtml($page->html, $url, $page->status ?? $plain->httpStatus)->viaBrowser();

        if ($rendered->successful()) {
            Log::info('Browser fallback recovered a job description.', $rendered->context() + [
                'url' => $url,
                'render_ms' => $page->elapsedMs,
            ]);

            return $rendered;
        }

        // A bot wall that only appears once JavaScript runs is a real finding and
        // more useful than "no content": it tells the caller to flag the posting
        // for review rather than schedule another attempt.
        if ($rendered->outcome === EnrichmentOutcome::Blocked) {
            Log::warning('Browser fallback hit a bot wall.', $rendered->context() + ['url' => $url]);

            return $rendered;
        }

        Log::info('Browser fallback found no usable description either.', $rendered->context() + [
            'url' => $url,
        ]);

        // Both paths came up short. Keep whichever holds more text so the caller
        // has the best available fragment; ties go to the plain fetch, since its
        // result is the cheaper one to reproduce.
        return $rendered->length() > $plain->length() ? $rendered : $plain;
    }

    /** Would {@see fetchDescription()} be blocked by the host's policy? */
    public function allowedByRobots(string $url): bool
    {
        return $this->robots->allows($url);
    }

    protected function get(string $url): Response
    {
        return Http::withHeaders([
            'User-Agent' => (string) config('enrichment.user_agent'),
            // Career pages content-negotiate; without these some return a JSON
            // API stub or a machine-translated locale.
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'en-US,en;q=0.9',
        ])
            ->timeout((int) config('enrichment.timeout', 20))
            ->withOptions([
                'allow_redirects' => [
                    'max' => (int) config('enrichment.max_redirects', 5),
                    'strict' => true,
                    'referer' => true,
                    'protocols' => ['http', 'https'],
                ],
            ])
            ->get($url);
    }

    /**
     * Turn one HTTP response into a result: status triage, bot-wall detection,
     * then selector extraction.
     */
    protected function extractFrom(Response $response, string $url): EnrichmentResult
    {
        $status = $response->status();

        // 401/403/429 from a career page is a bot wall far more often than a
        // genuinely private posting, and either way a browser retry is not the
        // answer — it is a block to be reviewed.
        if (in_array($status, [401, 403, 429], true)) {
            return EnrichmentResult::blocked("Fetch refused with HTTP {$status}.", $status);
        }

        if (! $response->successful()) {
            return EnrichmentResult::fetchFailed("Fetch returned HTTP {$status}.", $status);
        }

        return $this->extractFromHtml($response->body(), $url, $status);
    }

    /**
     * Selector extraction over one HTML document, whoever fetched it.
     *
     * Shared by the plain fetch and the browser fallback so that both are held to
     * the same selector list, the same size ceiling, the same noise stripping and
     * the same minimum length — the single place where "is this a job
     * description?" is decided.
     */
    protected function extractFromHtml(string $html, string $url, ?int $status = null): EnrichmentResult
    {
        if (mb_strlen($html) === 0) {
            return EnrichmentResult::noContent('Response body was empty.', httpStatus: $status);
        }

        $maxBytes = (int) config('enrichment.max_bytes', 3_000_000);

        if ($maxBytes > 0 && strlen($html) > $maxBytes) {
            return EnrichmentResult::fetchFailed(
                'Response exceeded the '.$maxBytes.'-byte ceiling; not a job posting page.',
                $status
            );
        }

        $crawler = $this->crawler($html, $url);

        if ($crawler === null) {
            return EnrichmentResult::fetchFailed('Response body could not be parsed as HTML.', $status);
        }

        if (($marker = $this->blockedMarkerIn($crawler)) !== null) {
            Log::warning('Enrichment hit a bot wall.', ['url' => $url, 'marker' => $marker]);

            return EnrichmentResult::blocked("Page title matched the block marker \"{$marker}\".", $status);
        }

        $this->stripNoise($crawler);

        [$text, $selector] = $this->bestCandidate($crawler, $url);
        $minimum = (int) config('enrichment.min_description_length', 400);

        if ($text !== null && mb_strlen($text) >= $minimum) {
            return EnrichmentResult::success($text, (string) $selector, $status);
        }

        return EnrichmentResult::noContent(
            $text === null
                ? 'No configured selector matched the page.'
                : 'Longest match was '.mb_strlen($text)." characters, under the {$minimum}-character minimum.",
            bestEffort: $text,
            selector: $selector,
            httpStatus: $status,
        );
    }

    /**
     * The longest text any configured selector yields, with the selector that
     * produced it.
     *
     * Longest rather than first-match, deliberately. The list is ordered from
     * specific to generic, but a specific selector can still match a truncated
     * "show more" stub while a later, broader one holds the full posting; taking
     * the first match is how the legacy scraper ended up storing teasers. Order
     * still decides ties, so an equally long specific match is preferred.
     *
     * @return array{0: ?string, 1: ?string}
     */
    protected function bestCandidate(Crawler $crawler, string $url): array
    {
        $best = null;
        $bestSelector = null;
        $bestLength = 0;

        foreach ($this->selectorsFor($url) as $selector) {
            $html = $this->firstMatchHtml($crawler, $selector);

            if ($html === null) {
                continue;
            }

            $text = $this->plainText($html);

            if ($text === null) {
                continue;
            }

            $length = mb_strlen($text);

            if ($length > $bestLength) {
                $best = $text;
                $bestSelector = $selector;
                $bestLength = $length;
            }
        }

        return [$best, $bestSelector];
    }

    /**
     * Inner HTML of the first node matching one selector, or null.
     *
     * Accepts both syntaxes the legacy code used: an XPath expression (prefixed
     * `xpath:`, or starting with `/` or `(`) and anything else as CSS. A
     * malformed selector is skipped rather than fatal — the list is
     * user-editable configuration, and one typo should not stop enrichment.
     */
    protected function firstMatchHtml(Crawler $crawler, string $selector): ?string
    {
        try {
            $matches = $this->isXPath($selector)
                ? $crawler->filterXPath($this->stripXPathPrefix($selector))
                : $crawler->filter($selector);

            if ($matches->count() === 0) {
                return null;
            }

            return $matches->first()->html();
        } catch (SyntaxErrorException $e) {
            Log::warning('Ignoring an invalid enrichment selector.', [
                'selector' => $selector,
                'reason' => $e->getMessage(),
            ]);

            return null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Host-specific selectors first, then the shared list.
     *
     * A `host_selectors` key matches the host and its subdomains, so one entry
     * for `lever.co` covers `jobs.lever.co`.
     *
     * @return list<string>
     */
    protected function selectorsFor(string $url): array
    {
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
        $specific = [];

        /** @var array<string, mixed> $hostSelectors */
        $hostSelectors = (array) config('enrichment.host_selectors', []);

        foreach ($hostSelectors as $configuredHost => $selectors) {
            $configuredHost = strtolower((string) $configuredHost);

            if ($host === $configuredHost || str_ends_with($host, '.'.$configuredHost)) {
                $specific = array_merge($specific, array_values((array) $selectors));
            }
        }

        $all = array_merge($specific, array_values((array) config('enrichment.selectors', [])));

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $s): string => is_string($s) ? trim($s) : '', $all),
            static fn (string $s): bool => $s !== '',
        )));
    }

    /**
     * Remove chrome, scripts and hidden elements in place, so a broad `main`
     * match doesn't drag navigation and cookie banners into the description.
     */
    protected function stripNoise(Crawler $crawler): void
    {
        foreach ((array) config('enrichment.strip_selectors', []) as $selector) {
            if (! is_string($selector) || trim($selector) === '') {
                continue;
            }

            try {
                foreach ($crawler->filter($selector) as $node) {
                    $node->parentNode?->removeChild($node);
                }
            } catch (Throwable) {
                // An unusable strip selector costs cleanliness, not correctness.
                continue;
            }
        }
    }

    /**
     * The block marker present in the page `<title>`, if any — the generalized
     * form of the legacy "Access Denied"/"Blocked" title check.
     */
    protected function blockedMarkerIn(Crawler $crawler): ?string
    {
        try {
            $titleNode = $crawler->filter('title');
            $title = $titleNode->count() > 0 ? mb_strtolower(trim($titleNode->first()->text(''))) : '';
        } catch (Throwable) {
            return null;
        }

        if ($title === '') {
            return null;
        }

        foreach ((array) config('enrichment.blocked_title_markers', []) as $marker) {
            if (is_string($marker) && $marker !== '' && str_contains($title, mb_strtolower($marker))) {
                return $marker;
            }
        }

        return null;
    }

    protected function crawler(string $html, string $url): ?Crawler
    {
        try {
            $crawler = new Crawler(null, $url);
            $crawler->addHtmlContent($html, 'UTF-8');

            return $crawler;
        } catch (Throwable $e) {
            Log::warning('Enrichment could not parse a response as HTML.', [
                'url' => $url,
                'reason' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * HTML reduced to readable plain text, preserving list and paragraph breaks.
     *
     * Scoring and tailoring read the description as text, so markup here would
     * be tokens spent on nothing; the line structure is kept because a JD's
     * requirement bullets stop being parseable as a run-on paragraph.
     */
    protected function plainText(string $html): ?string
    {
        $text = $html;

        // Some ATS feeds serve entity-escaped markup inside the page, so decode
        // until the string settles rather than once. Bounded so a pathological
        // input can't spin.
        for ($pass = 0; $pass < 4; $pass++) {
            $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if ($decoded === $text) {
                break;
            }

            $text = $decoded;
        }

        $text = preg_replace('#<br\s*/?>#i', "\n", $text);
        $text = preg_replace('#<li[^>]*>#i', "\n- ", $text);
        $text = preg_replace('#</(p|div|li|ul|ol|h[1-6]|tr|section|article)>#i', "\n", $text);
        $text = strip_tags($text);

        $text = str_replace("\u{a0}", ' ', $text);
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\s*\n\s*/', "\n", $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = trim($text);

        return $text === '' ? null : $text;
    }

    protected function isFetchableUrl(string $url): bool
    {
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        return in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }

    protected function isXPath(string $selector): bool
    {
        return str_starts_with($selector, 'xpath:')
            || str_starts_with($selector, '/')
            || str_starts_with($selector, '(');
    }

    protected function stripXPathPrefix(string $selector): string
    {
        return str_starts_with($selector, 'xpath:')
            ? trim(substr($selector, 6))
            : $selector;
    }
}

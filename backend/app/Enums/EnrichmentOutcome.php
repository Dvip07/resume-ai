<?php

namespace App\Enums;

/**
 * How one enrichment attempt on a job's `application_url` ended
 * (Requirement 3.4, task 9.1).
 *
 * The distinctions here exist because the caller has to make three different
 * decisions from them, and a bare success/failure boolean cannot carry any of
 * them:
 *
 *  1. Is a browser retry worth it (task 9.2)? Only for {@see NoContent} — a page
 *     whose JD is rendered client-side. Retrying a robots.txt refusal or a bot
 *     wall in a headless browser is either a policy violation or a waste.
 *  2. Should the job be flagged `needs_review` rather than silently left empty?
 *     Yes for {@see Blocked}, per design.md's error-handling section.
 *  3. Is retrying later plausible? Yes for {@see FetchFailed} (transient),
 *     no for {@see InvalidUrl} (permanent).
 */
enum EnrichmentOutcome: string
{
    /** Text was extracted and clears the minimum usable length. */
    case Success = 'success';

    /** `robots.txt` forbids this path for our user agent — do not fetch again. */
    case RobotsDisallowed = 'robots_disallowed';

    /**
     * The fetch succeeded but the page is a bot wall / CAPTCHA / denial, not the
     * posting. Flag for human review; a browser retry would hit the same wall.
     */
    case Blocked = 'blocked';

    /**
     * The page was retrieved and parsed but no selector yielded enough text —
     * typically a JS-rendered posting. This is the one outcome that warrants the
     * browser fallback.
     */
    case NoContent = 'no_content';

    /** Transport or HTTP-status failure. Retryable. */
    case FetchFailed = 'fetch_failed';

    /** The URL is absent or not an absolute http(s) address. Not retryable. */
    case InvalidUrl = 'invalid_url';

    /**
     * Would a headless-browser retry (task 9.2) plausibly do better than this
     * plain-fetch attempt?
     */
    public function warrantsBrowserFallback(): bool
    {
        return $this === self::NoContent;
    }

    /** Is a later retry of the same attempt worth scheduling? */
    public function isRetryable(): bool
    {
        return $this === self::FetchFailed;
    }
}

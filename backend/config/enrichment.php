<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Plain HTTP fetch (Requirement 3.4a)
    |--------------------------------------------------------------------------
    |
    | Enrichment always tries an ordinary GET + DOM parse before anything more
    | expensive (task 9.1); the headless-browser path (task 9.2) is the
    | fallback, not the default. These settings bound that first attempt.
    |
    | The user agent is deliberately honest and contactable: it is the same
    | string the robots.txt gate matches rules against, so pretending to be a
    | desktop Chrome — as the legacy scraper did — would mean claiming to be a
    | human while reading a machine-readable policy file. A site that wants to
    | refuse this crawler must be able to name it.
    |
    */

    'user_agent' => env(
        'ENRICHMENT_USER_AGENT',
        'ResumeAiBot/1.0 (+job description enrichment; contact: '.env('MAIL_FROM_ADDRESS', 'admin@example.com').')'
    ),

    /*
    | The token robots.txt `User-agent:` lines are matched against — the product
    | name from the string above, without the version or the comment. Kept
    | separate because the matching is a case-insensitive prefix test against a
    | bare product token, not against the whole header value.
    */
    'robots_user_agent' => env('ENRICHMENT_ROBOTS_USER_AGENT', 'ResumeAiBot'),

    'timeout' => (int) env('ENRICHMENT_TIMEOUT', 20),

    /*
    | Career pages redirect a lot (ATS vendors, locale splits, `?gh_src`
    | tracking), so redirects are followed, but a chain this long is a loop or a
    | consent-wall carousel.
    */
    'max_redirects' => (int) env('ENRICHMENT_MAX_REDIRECTS', 5),

    /*
    | Bytes. A job posting is a document, not a download; anything larger is a
    | mis-typed `application_url` pointing at an asset, and parsing it would
    | just burn memory.
    */
    'max_bytes' => (int) env('ENRICHMENT_MAX_BYTES', 3_000_000),

    /*
    |--------------------------------------------------------------------------
    | Minimum usable description length
    |--------------------------------------------------------------------------
    |
    | Extracted text shorter than this is treated as "the selector matched a
    | teaser or a cookie notice, not the job description", which sends the URL
    | to the browser fallback rather than persisting something unscoreable.
    | Mirrors `job_sources.min_description_length`, which decides whether a
    | discovered job needs enriching at all.
    |
    */

    'min_description_length' => (int) env(
        'ENRICHMENT_MIN_DESCRIPTION_LENGTH',
        (int) env('JOB_SOURCE_MIN_DESCRIPTION_LENGTH', 400)
    ),

    /*
    |--------------------------------------------------------------------------
    | robots.txt gate (Requirement 3.4c)
    |--------------------------------------------------------------------------
    |
    | Checked once per scheme+host and cached, so a discovery run that turns up
    | thirty Greenhouse postings costs one robots.txt request, not thirty.
    |
    | `allow_on_unreachable` is the interesting knob. A missing robots.txt (404)
    | always means "allowed" — that is what the standard says and it is the
    | common case. A *failed* fetch (timeout, DNS, 5xx) is different: the policy
    | exists and we could not read it. Convention among well-behaved crawlers is
    | to treat that as disallowed, which is the default here; the job is skipped
    | and retried later rather than fetched blind.
    |
    */

    'robots' => [

        'enabled' => (bool) env('ENRICHMENT_ROBOTS_ENABLED', true),

        /** Seconds. Long, because robots.txt changes rarely. */
        'cache_ttl' => (int) env('ENRICHMENT_ROBOTS_CACHE_TTL', 86400),

        /**
         * Seconds. Short, so an outage doesn't lock a host out for a day.
         */
        'error_cache_ttl' => (int) env('ENRICHMENT_ROBOTS_ERROR_CACHE_TTL', 900),

        'allow_on_unreachable' => (bool) env('ENRICHMENT_ROBOTS_ALLOW_ON_UNREACHABLE', false),

        /** Its own, tighter timeout: robots.txt is a small static file. */
        'timeout' => (int) env('ENRICHMENT_ROBOTS_TIMEOUT', 8),

    ],

    /*
    |--------------------------------------------------------------------------
    | Description selectors
    |--------------------------------------------------------------------------
    |
    | The generalized form of the selector list that used to be hard-coded in
    | `JobListingController::scrapeAndUpdateJobDescription` (task 9.1). Tried in
    | order; the first match whose text clears `min_description_length` wins.
    |
    | Both syntaxes are accepted, because the legacy code used both: a CSS
    | selector, or an XPath expression (anything starting with `/` or `(`, or
    | explicitly prefixed `xpath:`).
    |
    | Ordering matters — specific containers first, generic page landmarks last.
    | `main`/`article` at the tail are a deliberate best effort: better a whole
    | content column than nothing, and the length floor still rejects the
    | useless cases.
    |
    */

    'selectors' => [

        // LinkedIn's public posting markup, in both its plain and its
        // "show more" clamped form. The legacy XPath variant is kept because it
        // is stricter (markup div *inside* the section) and wins when a page
        // reuses the class name elsewhere.
        "xpath://section[contains(@class, 'show-more-less-html')]//div[contains(@class, 'show-more-less-html__markup')]",
        '.show-more-less-html__markup',
        '.jobs-box__html-content',
        '.jobs-description-content__text--stretch',

        // Seek / Indeed-style ATS and job-board containers.
        '[data-automation="jobAdDetails"]',
        '[data-automation="jobDescription"]',
        '#jobDescriptionText',

        // Greenhouse, Lever, Workday, Ashby.
        '#content .job__description',
        '.job__description',
        '[data-automation-id="jobPostingDescription"]',
        '.job-details-description',
        '.job-description',
        '#job-description',
        '.description__text',

        // Generic semantics, then page landmarks.
        '[itemprop="description"]',
        '.description',
        'article',
        'main',

    ],

    /*
    | Host-specific selectors tried *before* the shared list above. Keyed by
    | host; a key also matches its subdomains (`lever.co` covers
    | `jobs.lever.co`). This is the escape hatch for a site whose markup
    | collides with a generic selector, so one awkward domain never means
    | reordering the shared list for everyone.
    */
    'host_selectors' => [
        // 'boards.greenhouse.io' => ['#content'],
    ],

    /*
    | Elements stripped before extraction. Without this, a `main`/`article`
    | match drags in nav links, cookie banners and inline JSON-LD, all of which
    | are prompt tokens spent on nothing.
    */
    'strip_selectors' => [
        'script',
        'style',
        'noscript',
        'nav',
        'header',
        'footer',
        'form',
        'iframe',
        'svg',
        '[aria-hidden="true"]',
    ],

    /*
    |--------------------------------------------------------------------------
    | Bot-wall detection
    |--------------------------------------------------------------------------
    |
    | The legacy scraper's `Access Denied`/`Blocked` page-title check,
    | generalized (design.md "Error handling"). A page that matches is *not*
    | retried through the browser fallback and is not stored as a description:
    | it is a block, and the job is flagged for review instead of being left
    | silently empty.
    |
    | Matched case-insensitively against the `<title>` only. Page *body* text is
    | not searched — plenty of legitimate postings mention "captcha" or
    | "verify you are human" in an unrelated sentence.
    |
    */

    'blocked_title_markers' => [
        'access denied',
        'access to this page has been denied',
        'blocked',
        'forbidden',
        'are you a robot',
        'are you a human',
        'captcha',
        'attention required',
        'just a moment',
        'security check',
        'unusual traffic',
        'verify you are human',
    ],

];

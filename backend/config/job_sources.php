<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Registered job source providers (Requirements 3.1, 3.2)
    |--------------------------------------------------------------------------
    |
    | The authoritative list of discovery sources. JobSourceRegistry reads this
    | and resolves each class from the container; JobDiscoveryOrchestrator
    | (task 8.7) fans out to whatever is enabled here and names no provider
    | class itself. Adding a source is therefore additive: write the class,
    | add one line below.
    |
    | The array key is the source key. It MUST match the provider's own
    | `key()` — the registry refuses to resolve a mismatch, because the key is
    | persisted on `job_listings.source_key` and used as the `provider_usage`
    | counter name. Once rows exist under a key, don't rename it.
    |
    | 'enabled' defaults to true when omitted. Per-source credentials, endpoints
    | and daily limits stay in `config/services.php` next to the other
    | third-party settings (e.g. `services.adzuna`), not here.
    |
    | 'limit_config' is the optional escape hatch for that: ProviderDailyLimiter
    | reads `services.{key}.daily_limit` by default (Requirement 3.6), and a
    | provider whose services block is named differently from its key points at
    | the right path here.
    |
    |
    */

    'providers' => [

        'adzuna' => [
            'class' => App\Services\JobSources\Providers\AdzunaJobSourceProvider::class,
            'enabled' => env('JOB_SOURCE_ADZUNA_ENABLED', true),
        ],

        // Off by default: open decision #2 (which second aggregator) is
        // unconfirmed and JSearch needs a paid RapidAPI key. Set the key and
        // JOB_SOURCE_JSEARCH_ENABLED=true to bring it into the fan-out.
        'jsearch' => [
            'class' => App\Services\JobSources\Providers\JSearchJobSourceProvider::class,
            'enabled' => env('JOB_SOURCE_JSEARCH_ENABLED', false),
        ],

        // Public ATS boards. Registered under one key per ATS because
        // `job_listings.source_key` and the provider_usage counters need to
        // tell them apart, even though both share
        // AtsBoardJobSourceProvider's behaviour.
        //
        // On by default and still inert until the user names company slugs in
        // `services.greenhouse.companies` / `services.lever.companies`: with
        // none configured they make no requests. No API key is involved.
        'greenhouse' => [
            'class' => App\Services\JobSources\Providers\GreenhouseJobSourceProvider::class,
            'enabled' => env('JOB_SOURCE_GREENHOUSE_ENABLED', true),
        ],

        'lever' => [
            'class' => App\Services\JobSources\Providers\LeverJobSourceProvider::class,
            'enabled' => env('JOB_SOURCE_LEVER_ENABLED', true),
        ],

        // Web search through a search-augmented model, for roles the indexed
        // sources above never see (a company's own careers page, a board with
        // no configured slug). Needs no key of its own — it goes through
        // ModelRouterService, so OPENROUTER_API_KEY covers it, and that key is
        // already a prerequisite for scoring and tailoring.
        //
        // Unlike the other sources this one costs money per discovery run (web
        // search is billed per request on top of tokens), so
        // JOB_SOURCE_LLM_SEARCH_ENABLED=false is the switch when a run needs to
        // be cheap. Its results are unverified leads: the provider forces them
        // through enrichment before scoring — see the class docs.
        'llm_search' => [
            'class' => App\Services\JobSources\Providers\LlmWebSearchJobSourceProvider::class,
            'enabled' => env('JOB_SOURCE_LLM_SEARCH_ENABLED', true),
            // Its settings live under `services.llm_job_search`, not
            // `services.llm_search`, so the daily budget has to be named.
            'limit_config' => 'services.llm_job_search.daily_limit',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Default per-provider result ceiling (Requirement 3.6)
    |--------------------------------------------------------------------------
    |
    | Used by JobSearchQuery when the caller doesn't specify a limit. It bounds
    | the cost of one fan-out: worst case is this many jobs per enabled source.
    |
    */

    'default_limit' => (int) env(
        'JOB_SOURCE_DEFAULT_LIMIT',
        \App\Services\JobSources\JobSearchQuery::DEFAULT_LIMIT
    ),

    /*
    |--------------------------------------------------------------------------
    | Minimum usable description length
    |--------------------------------------------------------------------------
    |
    | Below this many characters a posting's description is treated as missing
    | or truncated and the job is a candidate for the enrichment stage
    | (Requirement 3.4, task 9). Aggregators commonly return a two-sentence
    | teaser, which is not enough to score a fit against.
    |
    */

    'min_description_length' => (int) env('JOB_SOURCE_MIN_DESCRIPTION_LENGTH', 400),

];

<?php

return [

  /*
  |--------------------------------------------------------------------------
  | Third Party Services
  |--------------------------------------------------------------------------
  |
  | This file is for storing the credentials for third party services such
  | as Mailgun, Postmark, AWS and more. This file provides the de facto
  | location for this type of information, allowing packages to have
  | a conventional file to locate the various service credentials.
  |
  */

  'mailgun' => [
    'domain' => env('MAILGUN_DOMAIN'),
    'secret' => env('MAILGUN_SECRET'),
    'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
    'scheme' => 'https',
  ],

  'postmark' => [
    'token' => env('POSTMARK_TOKEN'),
  ],

  'ses' => [
    'key' => env('AWS_ACCESS_KEY_ID'),
    'secret' => env('AWS_SECRET_ACCESS_KEY'),
    'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
  ],

  /*
  |--------------------------------------------------------------------------
  | Browser-automation worker (Requirement 3.4b, task 9.2)
  |--------------------------------------------------------------------------
  |
  | The Node/Playwright sidecar in `automation-worker/` at the repo root, read by
  | App\Services\Enrichment\AutomationWorkerClient. This is open decision #1
  | resolved in favour of Playwright over Symfony Panther: PHP keeps no browser
  | driver dependency, the browser is a separate process that can be restarted
  | and memory-capped on its own, and the same worker becomes the execution
  | engine for the ApplyAdapters in task 15.
  |
  | `enabled` is FALSE by default and that is deliberate. Enrichment's plain HTTP
  | fetch (task 9.1) handles most postings on its own, and a backend that assumed
  | a browser sidecar was running would break local dev and CI. Disabled, the
  | fallback is skipped with a log line and the posting keeps its `no_content`
  | outcome for a later retry — never an error.
  |
  | `token` is the shared secret sent as `X-Automation-Token`. The worker renders
  | whatever URL it is handed, so an unauthenticated one reachable beyond
  | loopback is an SSRF proxy; set this in any deployment where the worker is not
  | on 127.0.0.1.
  |
  | `timeout` sits above the worker's own AUTOMATION_WORKER_NAVIGATION_TIMEOUT_MS
  | (30s) on purpose, so the worker is the side that gives up first and can
  | answer with a reason instead of the call being severed. `connect_timeout` is
  | short: a worker that isn't running should fail immediately, since that is the
  | expected state in dev.
  |
  | `wait_for_selector`, when true, passes the selector the plain fetch was
  | hoping for as a render hint. Off by default — the worker waits for a settle
  | period instead, which is more robust across sites whose container name is not
  | in our list at all.
  |
  */
  'automation_worker' => [
    'enabled' => (bool) env('AUTOMATION_WORKER_ENABLED', false),
    'base_url' => rtrim((string) env('AUTOMATION_WORKER_BASE_URL', 'http://127.0.0.1:8081'), '/'),
    'render_path' => env('AUTOMATION_WORKER_RENDER_PATH', '/render'),
    'health_path' => env('AUTOMATION_WORKER_HEALTH_PATH', '/health'),
    'token' => env('AUTOMATION_WORKER_TOKEN'),
    'timeout' => (int) env('AUTOMATION_WORKER_TIMEOUT', 45),
    'connect_timeout' => (int) env('AUTOMATION_WORKER_CONNECT_TIMEOUT', 5),
    // Forwarded as the render's `waitUntil`/`settleMs`. Left null, the worker's
    // own defaults apply, which is the normal case — these exist so a stubborn
    // site can be tuned from the Laravel side without redeploying the worker.
    'wait_until' => env('AUTOMATION_WORKER_WAIT_UNTIL'),
    'settle_ms' => env('AUTOMATION_WORKER_SETTLE_MS') === null
      ? null
      : (int) env('AUTOMATION_WORKER_SETTLE_MS'),
    'wait_for_selector' => (bool) env('AUTOMATION_WORKER_WAIT_FOR_SELECTOR', false),

    // Apply automation (Req 9.1/9.2) shares this block: same address, same
    // token, a second endpoint. Only the budget differs — a multi-page form
    // outlives a single render, so `apply_timeout` defaults well above
    // `timeout`. `apply_timeout_ms` is the worker-side budget and, like the
    // render ones, deliberately sits below Laravel's so the worker gives the
    // reason. `apply_user_agent` left null keeps the worker's own desktop
    // string: an apply run is completing a form, not identifying a crawler.
    'apply_path' => env('AUTOMATION_WORKER_APPLY_PATH', '/apply'),
    'apply_timeout' => (int) env('AUTOMATION_WORKER_APPLY_TIMEOUT', 180),
    'apply_timeout_ms' => env('AUTOMATION_WORKER_APPLY_TIMEOUT_MS') === null
      ? null
      : (int) env('AUTOMATION_WORKER_APPLY_TIMEOUT_MS'),
    'apply_user_agent' => env('AUTOMATION_WORKER_APPLY_USER_AGENT'),
  ],

  /*
  |--------------------------------------------------------------------------
  | JSearch job source, via RapidAPI (Requirement 3.1)
  |--------------------------------------------------------------------------
  |
  | Read by App\Services\JobSources\Providers\JSearchJobSourceProvider. This
  | block replaces the bare `rapidapi.key` entry that only the dead
  | `linkedin-job-api` blocks in JobListingController referenced (Req 12.3);
  | `JSEARCH_RAPIDAPI_KEY` falls back to the old `RAPIDAPI_KEY` name so an
  | existing .env keeps working.
  |
  | The source is registered disabled by default in `config/job_sources.php`
  | because open decision #2 (which second aggregator, and a paid RapidAPI
  | account) is not confirmed. The provider throws rather than firing
  | unauthenticated calls if it is enabled with no key.
  |
  | `date_posted` is JSearch's recency filter: all | today | 3days | week |
  | month. `num_pages` is how many result pages one call returns (each page is
  | ~10 postings and counts as one request against the plan's quota, so 1 keeps
  | a discovery pass cheap). `country` is optional — left empty, the free-text
  | location in the query decides.
  |
  | `salary_period_multipliers` annualizes JSearch's per-period bounds, since
  | NormalizedJob is defined in whole annual units. HOURLY assumes a 2080-hour
  | year (40h x 52w) and DAILY a 260-day one; a period missing from this map
  | means the salary is dropped rather than misread by a factor of ~2000.
  |
  | `currencies` is the fallback when a posting omits `job_salary_currency`,
  | keyed by JSearch's 2-letter `job_country`. Unmapped means the salary is
  | dropped, not guessed — the tier thresholds do no FX conversion.
  |
  */

  'jsearch' => [
    'api_key' => env('JSEARCH_RAPIDAPI_KEY', env('RAPIDAPI_KEY')),
    'host' => env('JSEARCH_RAPIDAPI_HOST', 'jsearch.p.rapidapi.com'),
    'base_url' => env('JSEARCH_BASE_URL', 'https://jsearch.p.rapidapi.com'),
    'date_posted' => env('JSEARCH_DATE_POSTED', 'week'),
    'num_pages' => (int) env('JSEARCH_NUM_PAGES', 1),
    'country' => env('JSEARCH_COUNTRY', ''),
    'timeout' => (int) env('JSEARCH_TIMEOUT', 15),
    // Requirement 3.6: calls per user per day, enforced by
    // ProviderDailyLimiter before the provider is invoked. Low by default
    // because the RapidAPI free/basic plans are metered monthly. Unset or 0
    // means unlimited.
    'daily_limit' => (int) env('JSEARCH_DAILY_LIMIT', 10),
    'salary_period_multipliers' => [
      'HOUR' => 2080,
      'HOURLY' => 2080,
      'DAY' => 260,
      'DAILY' => 260,
      'WEEK' => 52,
      'WEEKLY' => 52,
      'MONTH' => 12,
      'MONTHLY' => 12,
      'YEAR' => 1,
      'YEARLY' => 1,
      'ANNUAL' => 1,
    ],
    'currencies' => [
      'at' => 'EUR',
      'au' => 'AUD',
      'be' => 'EUR',
      'br' => 'BRL',
      'ca' => 'CAD',
      'ch' => 'CHF',
      'de' => 'EUR',
      'es' => 'EUR',
      'fr' => 'EUR',
      'gb' => 'GBP',
      'ie' => 'EUR',
      'in' => 'INR',
      'it' => 'EUR',
      'mx' => 'MXN',
      'nl' => 'EUR',
      'nz' => 'NZD',
      'pl' => 'PLN',
      'sg' => 'SGD',
      'us' => 'USD',
      'za' => 'ZAR',
    ],
  ],

  /*
  |--------------------------------------------------------------------------
  | Adzuna job source (Requirement 3.1)
  |--------------------------------------------------------------------------
  |
  | Read by App\Services\JobSources\Providers\AdzunaJobSourceProvider. The
  | endpoint and credentials are unchanged from the Blade-era Guzzle call this
  | provider replaced; what used to be hard-coded there is configuration now.
  |
  | `country` is the Adzuna country vertical. Adzuna is per-country: the path
  | segment selects the index AND determines the currency of every salary
  | figure in the response (the payload itself never names a currency). The
  | legacy call hard-coded `ca`, so that stays the default.
  |
  | `currencies` maps each supported country vertical to its ISO-4217 code.
  | NormalizedJob requires a currency whenever a salary is present and does no
  | FX conversion, so an unmapped country means the provider drops the salary
  | rather than labelling a figure with the wrong currency. Extend this map
  | when Adzuna adds a country.
  |
  */

  'adzuna' => [
    'app_id' => env('ADZUNA_APP_ID'),
    'app_key' => env('ADZUNA_APP_KEY'),
    'base_url' => env('ADZUNA_BASE_URL', 'https://api.adzuna.com/v1/api/jobs'),
    'country' => env('ADZUNA_COUNTRY', 'ca'),
    'results_per_page' => (int) env('ADZUNA_RESULTS_PER_PAGE', 5),
    'max_days_old' => (int) env('ADZUNA_MAX_DAYS_OLD', 30),
    'timeout' => (int) env('ADZUNA_TIMEOUT', 15),
    // Requirement 3.6: calls per user per day (ProviderDailyLimiter). Adzuna's
    // free tier allows ~250 calls/day across the whole app, so keep this well
    // under it once there is more than one user. 0/unset means unlimited.
    'daily_limit' => (int) env('ADZUNA_DAILY_LIMIT', 25),
    'currencies' => [
      'at' => 'EUR',
      'au' => 'AUD',
      'be' => 'EUR',
      'br' => 'BRL',
      'ca' => 'CAD',
      'ch' => 'CHF',
      'de' => 'EUR',
      'es' => 'EUR',
      'fr' => 'EUR',
      'gb' => 'GBP',
      'in' => 'INR',
      'it' => 'EUR',
      'mx' => 'MXN',
      'nl' => 'EUR',
      'nz' => 'NZD',
      'pl' => 'PLN',
      'sg' => 'SGD',
      'us' => 'USD',
      'za' => 'ZAR',
    ],
  ],

  /*
  |--------------------------------------------------------------------------
  | Public ATS job boards: Greenhouse and Lever (Requirement 3.1)
  |--------------------------------------------------------------------------
  |
  | Read by App\Services\JobSources\Providers\GreenhouseJobSourceProvider and
  | LeverJobSourceProvider (both extend AtsBoardJobSourceProvider). These are
  | the companies' own public, machine-readable board feeds — the same JSON
  | their careers pages consume — so there is no key and no quota to manage.
  |
  | `companies` is the whole point of these sources: a board belongs to one
  | company, so nothing is polled until the user names the companies they want
  | to target. Empty (the default) means the source makes no requests at all,
  | which is why both can ship enabled in config/job_sources.php without doing
  | anything unexpected.
  |
  | The .env form is a comma-separated list of slugs, each optionally followed
  | by `:Display Name`:
  |
  |   GREENHOUSE_COMPANIES="stripe,figma,globex-inc-1:Globex Inc"
  |   LEVER_COMPANIES="netflix,shopify"
  |
  | The slug is the path segment in the board URL — for
  | `boards.greenhouse.io/acme` or `jobs.lever.co/acme` it's `acme`. The
  | display name is optional but worth setting when the slug isn't the
  | company's name: it is written to `job_listings.company`, shown to the user,
  | and feeds the dedupe hash. Without one the slug is title-cased.
  |
  | Board APIs accept no search parameters — a request returns every open role,
  | including ones nothing like what the user wants — so the query is applied
  | client-side. `match_titles` keeps only postings whose title contains all the
  | words of one of the user's target roles; turning it off means every open
  | role on every configured board enters the pipeline (and gets scored, at
  | cost). `filter_by_location` is off because board location strings are free
  | text ("SF Bay Area (Hybrid)", "Remote — Americas") and substring-matching a
  | user's location against them drops more real matches than it removes noise;
  | a `remoteOnly` query is still honoured, since that signal is reliable.
  |
  | Neither API exposes structured pay, so these jobs carry no salary and model
  | tier selection falls back to the complexity heuristic (Requirement 4.4).
  |
  */

  'greenhouse' => [
    'base_url' => env('GREENHOUSE_BASE_URL', 'https://boards-api.greenhouse.io/v1/boards'),
    'companies' => env('GREENHOUSE_COMPANIES', ''),
    'timeout' => (int) env('GREENHOUSE_TIMEOUT', 15),
    // `content=true` returns the full JD inline, which is most of the value of
    // this source: those jobs skip the enrichment stage (Requirement 3.4).
    // Disable only if a very large board's response becomes a problem.
    'include_content' => (bool) env('GREENHOUSE_INCLUDE_CONTENT', true),
    'match_titles' => (bool) env('GREENHOUSE_MATCH_TITLES', true),
    'filter_by_location' => (bool) env('GREENHOUSE_FILTER_BY_LOCATION', false),
    // Unlimited by default (Requirement 3.6): these are the companies' own
    // public board feeds — no key, no quota, no per-call cost — so a budget
    // would only get in the way. Set a number if a board starts throttling.
    'daily_limit' => (int) env('GREENHOUSE_DAILY_LIMIT', 0),
  ],

  'lever' => [
    'base_url' => env('LEVER_BASE_URL', 'https://api.lever.co/v0/postings'),
    'companies' => env('LEVER_COMPANIES', ''),
    'timeout' => (int) env('LEVER_TIMEOUT', 15),
    'match_titles' => (bool) env('LEVER_MATCH_TITLES', true),
    'filter_by_location' => (bool) env('LEVER_FILTER_BY_LOCATION', false),
    // Unlimited by default, for the same reason as Greenhouse above.
    'daily_limit' => (int) env('LEVER_DAILY_LIMIT', 0),
  ],

  /*
  |--------------------------------------------------------------------------
  | LLM web-search job source (Requirement 3.1)
  |--------------------------------------------------------------------------
  |
  | Read by App\Services\JobSources\Providers\LlmWebSearchJobSourceProvider,
  | which asks a web-search-augmented model (see the `web_search` tier in the
  | `openrouter` block below) for postings matching the user's roles and
  | location. It needs no key of its own: the call goes through
  | ModelRouterService, so `OPENROUTER_API_KEY` is the only credential.
  |
  | Everything this source returns is an UNVERIFIED LEAD. A model can invent a
  | company, resurrect a filled role, or cite a URL that 404s, so the provider
  | deliberately discards any description the model writes: the job lands with
  | a null description, which forces the enrichment stage (Requirement 3.4) to
  | fetch the real posting at `application_url` before anything is scored. Do
  | not "optimize" that away — an LLM-authored description would be scored, and
  | tailored against, as though it were the employer's own words.
  |
  | `tier` names the openrouter tier to pin. `task_type` is what shows up in
  | `model_usage_logs.task_type`, so this source's spend is separable from
  | scoring/tailoring spend. `max_results` caps what the prompt asks for
  | independently of the query's own limit, because asking one call for 100
  | postings mostly produces filler.
  |
  | `max_posted_age_days` bounds how old a lead may claim to be; `trust_dates`
  | turns model-reported dates off entirely if they prove unreliable (a null
  | postedAt is harmless — it just means "unknown"). `require_salary_currency`
  | mirrors the aggregator providers: a figure with no currency is dropped
  | rather than misread by the tier thresholds, and a model is even more likely
  | than an API to omit one.
  |
  */

  'llm_job_search' => [
    'tier' => env('LLM_JOB_SEARCH_TIER', 'web_search'),
    'task_type' => env('LLM_JOB_SEARCH_TASK_TYPE', 'job_search'),
    'max_results' => (int) env('LLM_JOB_SEARCH_MAX_RESULTS', 15),
    'max_posted_age_days' => (int) env('LLM_JOB_SEARCH_MAX_POSTED_AGE_DAYS', 30),
    'trust_dates' => (bool) env('LLM_JOB_SEARCH_TRUST_DATES', true),
    // Requirement 3.6: the tightest budget of any source, because this is the
    // only one billed per request on top of tokens. Reached through
    // `limit_config` in config/job_sources.php, since the provider key is
    // `llm_search` while this block is `llm_job_search`. 0/unset = unlimited.
    'daily_limit' => (int) env('LLM_JOB_SEARCH_DAILY_LIMIT', 4),
  ],

  /*
  |--------------------------------------------------------------------------
  | OpenRouter tiered model routing (Requirement 4)
  |--------------------------------------------------------------------------
  |
  | Every LLM call in the pipeline goes through ModelRouterService, which reads
  | this block. Nothing here may be duplicated as a literal in feature code —
  | model choices, thresholds and timeouts are config-only so they can be
  | retuned as OpenRouter pricing/availability changes (Req 4.2).
  |
  | The API key is read from the environment only and is never committed
  | (Req 4.7). `.env.example` ships it empty.
  |
  | Model slugs below were verified against the live OpenRouter model list
  | (https://openrouter.ai/api/v1/models, which backs https://openrouter.ai/models)
  | on 2026-09-04. Prices in the comments are USD per 1M tokens as
  | input/output at that date, recorded so a future reader can tell how stale
  | these defaults are. Every listed model reports `structured_outputs`
  | support, which ModelRouterService relies on for JSON-schema responses.
  | Each tier lists more than one model so a failure can retry on a
  | same-tier fallback (Req 4.5).
  |
  */
  'openrouter' => [
    'api_key' => env('OPENROUTER_API_KEY'),
    'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),

    // Sent by OpenRouter convention to attribute traffic to this app. Both are
    // optional; they only affect OpenRouter's own reporting.
    'referer' => env('OPENROUTER_REFERER', env('APP_URL')),
    'title' => env('OPENROUTER_TITLE', env('APP_NAME', 'Resume AI')),

    // Seconds to wait for a completion before treating the call as timed out
    // and moving to the next model in the tier (Req 4.5). Tailoring prompts
    // are long-running, hence the generous default.
    'timeout' => (int) env('OPENROUTER_TIMEOUT', 120),
    // Connection-establishment budget, kept short so a dead endpoint fails
    // fast instead of burning the full request timeout.
    'connect_timeout' => (int) env('OPENROUTER_CONNECT_TIMEOUT', 10),
    // Attempts per model before falling through to the next model in the tier.
    'max_attempts_per_model' => (int) env('OPENROUTER_MAX_ATTEMPTS_PER_MODEL', 2),
    // Linear backoff between attempts on the *same* model (multiplied by the
    // attempt number). No wait is applied when switching models, since a
    // different model means different capacity. 0 disables the wait.
    'retry_delay_ms' => (int) env('OPENROUTER_RETRY_DELAY_MS', 250),

    /*
    | Ordered tier → model-list map (Req 4.2). Position matters: index 0 is the
    | preferred model, later entries are same-tier fallbacks tried in order.
    */
    'tiers' => [
      // Cheap: high-volume, low-stakes work (resume parsing, cover-letter
      // requirement classification).
      'cheap' => [
        'google/gemini-2.5-flash-lite',   // $0.10 / $0.40
        'openai/gpt-4.1-nano',            // $0.10 / $0.40
        'openai/gpt-oss-120b',            // $0.04 / $0.17
      ],
      // Standard: default for scoring and most tailoring.
      'standard' => [
        'google/gemini-2.5-flash',        // $0.30 / $2.50
        'openai/gpt-4.1-mini',            // $0.40 / $1.60
        'anthropic/claude-haiku-4.5',     // $1.00 / $5.00
      ],
      // Premium: high-salary or high-complexity applications where better
      // tailoring is worth the spend.
      'premium' => [
        'anthropic/claude-sonnet-4.6',    // $3.00 / $15.00
        'openai/gpt-5.1',                 // $1.25 / $10.00
        'google/gemini-2.5-pro',          // $1.25 / $10.00
      ],
      /*
      | Not a cost tier: a capability tier, pinned via the `$tierOverride`
      | argument to ModelRouterService::complete() by
      | LlmWebSearchJobSourceProvider (task 8.5). Every model here MUST be able
      | to search the live web, otherwise the source returns whatever the
      | model remembers from training — i.e. stale or invented postings.
      |
      | The `:online` suffix is OpenRouter's shortcut for its web plugin and
      | works on any model (verified against
      | https://openrouter.ai/docs/features/web-search on 2026-09-04): it uses
      | the provider's native search where available (Google, OpenAI,
      | Anthropic, Perplexity) and Exa's search API otherwise. That is why no
      | separate Exa/Tavily integration is needed — the fallback named in
      | Requirement 3.1 is already what OpenRouter runs underneath.
      |
      | Base models are chosen from the standard tier because they report
      | `structured_outputs`, which this source's JSON-schema response depends
      | on. Perplexity's `sonar`/`sonar-pro` are deliberately NOT used: they
      | search natively but do not support structured outputs, so the schema
      | request would fail. Web search adds a per-request charge on top of
      | token cost, which is the other reason this stays a distinct tier
      | rather than something every task can reach for.
      */
      'web_search' => [
        'google/gemini-2.5-flash:online',
        'openai/gpt-4.1-mini:online',
        'anthropic/claude-haiku-4.5:online',
      ],
    ],

    // Tier used when neither salary nor complexity data is available, and the
    // floor that salary/complexity escalation starts from. Never the most
    // expensive tier (Req 4.4).
    'default_tier' => env('OPENROUTER_DEFAULT_TIER', 'cheap'),

    /*
    | Salary-based escalation (Req 4.3). Read as: annual salary (in the
    | listing's currency, after normalization) at or above the value escalates
    | to that tier. Highest matching threshold wins, so 160k → premium.
    | Below the lowest threshold the tier stays at `default_tier`.
    */
    'salary_thresholds' => [
      'standard' => (int) env('OPENROUTER_SALARY_THRESHOLD_STANDARD', 90000),
      'premium' => (int) env('OPENROUTER_SALARY_THRESHOLD_PREMIUM', 150000),
    ],

    /*
    | Complexity-based escalation, used when a listing has no salary data
    | (Req 4.4). Compared against the 0-100 score ComplexityScorer derives from
    | the weights below; same highest-match-wins reading as salary.
    */
    'complexity_thresholds' => [
      'standard' => (int) env('OPENROUTER_COMPLEXITY_THRESHOLD_STANDARD', 40),
      'premium' => (int) env('OPENROUTER_COMPLEXITY_THRESHOLD_PREMIUM', 75),
    ],

    /*
    | Inputs to the 0-100 complexity score. `weights` must be read as relative
    | contributions of each signal; `*_saturation` is the raw value at which a
    | signal contributes its full weight (anything larger is clamped). Kept in
    | config so the heuristic is tunable without a code change (Req 4.4).
    */
    'complexity' => [
      'weights' => [
        'jd_length' => (int) env('OPENROUTER_COMPLEXITY_WEIGHT_JD_LENGTH', 40),
        'requirement_count' => (int) env('OPENROUTER_COMPLEXITY_WEIGHT_REQUIREMENTS', 30),
        'skill_gap' => (int) env('OPENROUTER_COMPLEXITY_WEIGHT_SKILL_GAP', 30),
      ],
      // A ~6000-character JD, 20 detected requirements, or 10 missing skills
      // each count as maximally complex for their signal.
      'jd_length_saturation' => (int) env('OPENROUTER_COMPLEXITY_JD_LENGTH_SATURATION', 6000),
      'requirement_count_saturation' => (int) env('OPENROUTER_COMPLEXITY_REQUIREMENT_SATURATION', 20),
      'skill_gap_saturation' => (int) env('OPENROUTER_COMPLEXITY_SKILL_GAP_SATURATION', 10),
    ],
  ],

];

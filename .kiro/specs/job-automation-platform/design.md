# Design Document: Job Automation Platform (Decoupled Backend + Frontend)

## Overview

This design migrates `resume-ai` from a single Laravel/Blade monolith into
**two independent top-level projects** — `backend/` (Laravel API) and
`frontend/` (standalone React app) — connected only over HTTP, and layers a
job-automation pipeline on top of the backend:

```
Discover → Enrich → Score (2-prompt) → [Tailor Resume/Cover Letter → Apply] | Store Only
```

Every stage after "Discover" is a queued Laravel job, entirely inside
`backend/`. The pipeline is driven by a small set of new domain services
(`ModelRouterService`, job source providers, `LatexRenderService`,
`ApplyAdapter` implementations) rather than controller logic, so the API
layer stays thin. None of this pipeline logic is exposed to or run inside
`frontend/` — the frontend only ever talks to `backend/`'s REST API.

**Migration strategy:** strangler pattern, in two phases:
1. **Phase A (in-place):** build real `/api/*` endpoints inside the existing
   single-repo Laravel app, alongside the Blade routes, until the API surface
   has parity with what the frontend will need (see Requirement 12.1). Task 1
   (bug fixes, model consolidation, migration hygiene) is already done as
   part of this phase.
2. **Phase B (folder split):** move the current repository root's Laravel
   application into a new `backend/` subfolder (paths, composer autoload
   roots, and any absolute-path assumptions updated accordingly), and
   scaffold `frontend/` as a brand-new, independent React project. From this
   point on, frontend screens are built directly in `frontend/` against the
   now-stable `backend/` API — there is no "SPA embedded in Laravel" phase.

This design describes the target end-state architecture; the tasks document
sequences the incremental steps, including the folder-move mechanics.

## Architecture

### Repository layout (target end-state)

```
resume-ai/
├── backend/                 # Laravel API project (independent composer.json)
│   ├── app/
│   ├── config/
│   ├── database/
│   ├── routes/
│   │   ├── api.php          # all authenticated app functionality
│   │   └── web.php          # auth-related routes only, if kept server-rendered (Req 12.4)
│   ├── artisan
│   ├── composer.json
│   └── .env / .env.example
├── frontend/                 # Standalone React project (independent package.json)
│   ├── src/
│   │   ├── routes/ (or pages/)   # login, dashboard, resumes, jobs, applications, profile
│   │   ├── api/                  # typed API client wrapping fetch/axios calls to backend
│   │   └── ...
│   ├── package.json
│   ├── vite.config.ts (or equivalent)
│   └── .env / .env.local     # VITE_API_BASE_URL etc.
└── README.md                 # top-level pointer to backend/README.md and frontend/README.md
```

Neither folder depends on the other's build tooling. `backend/` has no
`node_modules`/React dependencies; `frontend/` has no PHP/Composer
dependencies. They can be run, tested, and deployed independently — the only
coupling is the frontend's configured API base URL and the backend's CORS
allow-list (Requirement 1B).

### High-level components

```
┌─────────────────────────────────────────────────────────────────┐
│ frontend/ — standalone React app (its own Vite/CRA/Next.js,      │
│ React Router, TanStack Query, own package.json & dev server)     │
│  - Auth, Dashboard, Resumes, Jobs, Applications, Profile         │
└───────────────────────────────┬───────────────────────────────────┘
                                 │ HTTPS, JSON, cross-origin
                                 │ Authorization: Bearer <sanctum token>
┌───────────────────────────────▼───────────────────────────────────┐
│ backend/ — Laravel API (routes/api.php, api/* controllers,       │
│ FormRequests) — own composer.json, own artisan, own .env          │
├─────────────────────────────────────────────────────────────────┤
│ Domain Services                                                  │
│  - ModelRouterService (OpenRouter tiered routing + usage log)     │
│  - JobSourceProvider[] (Adzuna, JSearch, ATS boards, LLM search)  │
│  - JobEnrichmentService (fetch full JD)                          │
│  - JobScoringService (2-prompt: fit analysis -> rating)           │
│  - LatexRenderService (template -> .tex -> PDF via tectonic)      │
│  - ApplyAdapter[] (GreenhouseAdapter, LeverAdapter, WorkdayAdapter,│
│    LinkedInEasyApplyAdapter [opt-in])                             │
├─────────────────────────────────────────────────────────────────┤
│ Queued Jobs (ShouldQueue, chained)                                │
│  DiscoverJobsForUser -> EnrichJobDescription -> ScoreJobListing    │
│  -> TailorResume -> TailorCoverLetter -> SubmitApplication         │
├─────────────────────────────────────────────────────────────────┤
│ Storage: MySQL (relational data) + S3 (PDFs, screenshots)         │
│ Queue: database driver -> Supervisor-managed queue:work workers   │
└─────────────────────────────────────────────────────────────────┘
```

### Why these architectural choices

- **Separate `backend/`/`frontend/` folders with independent tooling** (this
  request) over a same-origin SPA bundled via `laravel-vite-plugin`: gives
  each side its own dependency graph, its own CI build/test job, its own
  deploy target (e.g. backend on a PHP host/container, frontend on a static
  host/CDN), and lets frontend and backend developers work without either
  side's install step blocking the other. The tradeoff versus a same-origin
  SPA is that auth can no longer rely on same-origin cookies, and CORS must
  be configured deliberately (see below).
- **Sanctum API tokens over `Authorization: Bearer`** (not SPA cookie auth)
  because the frontend is served from a different origin/port than the
  backend and does not share the backend's session. Login issues a Sanctum
  personal access token; the frontend stores it (e.g. in memory + a
  refresh-safe secure storage mechanism such as an httpOnly-cookie-set-by-
  backend-on-login, or `localStorage` if the team accepts the associated XSS
  tradeoff — a decision to confirm, see Open Decisions) and sends it as a
  Bearer header on every request. `config/cors.php` on the backend allow-lists
  only the frontend's known origins (Requirement 1.9).
- **Provider/adapter pattern for job sources and apply targets** so adding a
  new job board or ATS is additive (new class implementing an interface),
  never a change to the scoring/tailoring core — directly satisfies
  Requirement 3.2 and keeps the LinkedIn-risk decision isolated to one class.
- **Job chaining over a monolithic "pipeline job"** so each stage is
  independently retryable, independently observable in `failed_jobs`, and
  independently rate-limited (discovery can run hourly while apply automation
  runs with stricter caps).
- **`tectonic` for LaTeX→PDF** over `pdflatex`/`xelatex`: it's a self-contained
  Rust binary with bundled TeX packages (no full TeX Live install, no
  `require-dev`-only fragility like the current Panther situation), making it
  practical to install in a Docker/production image. `pdflatex` remains a
  documented fallback if `tectonic` is unavailable in a given environment.
- **Headless browser only where necessary**: enrichment tries a plain HTTP
  fetch + DOM parse first (fast, cheap, no bot-detection surface); a headless
  browser (Playwright via a small Node microservice, replacing Panther) is
  only invoked when the plain fetch yields no usable content. This also
  becomes the execution engine for `ApplyAdapter`s, since form-filling
  requires real browser interaction (JS-heavy target application forms).

### Why Playwright (Node) instead of Panther (PHP) for browser automation

Panther is currently `require-dev`-only and wraps Selenium/ChromeDriver, which
is heavier to operate in production and has weaker modern anti-detection and
auto-waiting ergonomics than Playwright. Design decision: introduce a small,
separate **Node/Playwright automation worker** (its own process, invoked by
Laravel via an internal HTTP endpoint or a Redis/DB job queue it polls) that
handles both JD enrichment scraping and `ApplyAdapter` form automation. This
keeps PHP application code free of browser-driver dependencies and lets the
automation worker be scaled/deployed independently. If the team prefers to
stay single-language, Symfony Panther can be kept as a fallback for
enrichment only (not for apply automation, which needs more robust
interaction primitives); this is called out as an open decision in the tasks
doc.

## Components and Interfaces

### 1. `ModelRouterService`

```php
interface ModelRouterService
{
    /**
     * @param string $taskType e.g. 'jd_fit_analysis', 'jd_rating',
     *                          'resume_tailor', 'cover_letter_tailor'
     */
    public function complete(
        string $taskType,
        array $messages,
        ModelTierContext $context,
        array $jsonSchema = null,
    ): ModelCompletionResult;
}

class ModelTierContext
{
    public ?int $salaryMin;
    public ?int $salaryMax;
    public ?string $currency;
    public int $complexityScore; // derived from JD length, requirement count, skill-gap size
}

class ModelCompletionResult
{
    public string $content;
    public array $parsedJson;      // if $jsonSchema was passed and parse succeeded
    public string $modelUsed;
    public string $tierUsed;
    public int $inputTokens;
    public int $outputTokens;
    public float $estimatedCostUsd;
}
```

- Config-driven tiers in `config/services.php`:
  ```php
  'openrouter' => [
      'api_key' => env('OPENROUTER_API_KEY'),
      'base_url' => 'https://openrouter.ai/api/v1',
      'tiers' => [
          'cheap'    => ['openai/gpt-5.6-luna', 'qwen/qwen3.7-flash'],
          'standard' => ['anthropic/claude-3.7-sonnet', 'openai/gpt-5.6-terra'],
          'premium'  => ['anthropic/claude-opus-4', 'openai/gpt-5.6-titan'],
      ],
      'salary_thresholds' => ['standard' => 90000, 'premium' => 150000],
      'complexity_thresholds' => ['standard' => 40, 'premium' => 75],
  ],
  ```
  Model IDs above are illustrative placeholders — actual OpenRouter model
  slugs and pricing must be confirmed against `https://openrouter.ai/models`
  at implementation time, since pricing/availability changes frequently (per
  research: OpenRouter listed 850+ models with per-million-token input prices
  ranging roughly $0.01–$150 as of mid-2026 ([pricepertoken.com](https://pricepertoken.com/endpoints/openrouter))).
- Tier selection algorithm: `salary present → salary-based tier; else →
  complexity-based tier`, both configurable thresholds, never hard-coded
  (Req 4.2–4.4).
- Every call is wrapped with retry-on-fallback-model-within-tier (OpenRouter
  supports passing an array of `models` for automatic fallback) and logs to
  `model_usage_logs` (Req 4.5–4.6) regardless of success/failure.
- Uses OpenRouter's JSON-schema/structured-output mode where supported for
  scoring/tailoring prompts, falling back to a fenced-JSON-extraction parser
  (reusing the pattern already proven in `AnalyzeResumeJob::processOllamaResponse`)
  when a model doesn't support structured output.

### 2. Job Source Providers

```php
interface JobSourceProvider
{
    public function key(): string; // 'adzuna', 'jsearch', 'greenhouse', 'llm_search'
    public function search(JobSearchQuery $query): Collection; // Collection<NormalizedJob>
}

class NormalizedJob
{
    public string $sourceKey;
    public ?string $externalId;
    public string $title;
    public string $company;
    public string $location;
    public ?string $description;   // often partial/null from aggregators
    public string $applicationUrl;
    public ?Carbon $postedAt;
    public ?int $salaryMin;
    public ?int $salaryMax;
    public ?string $currency;
}
```

- `AdzunaJobSourceProvider` — refactor of existing Guzzle call in
  `JobListingController::index`, unchanged endpoint/auth.
- `JSearchJobSourceProvider` (RapidAPI aggregator, re-indexes LinkedIn/Indeed/
  Glassdoor postings without directly scraping them) — new, replaces the dead
  `linkedin-job-api` RapidAPI integration with a maintained aggregator.
- `AtsBoardJobSourceProvider` (one implementation per ATS: Greenhouse
  `boards-api.greenhouse.io/v1/boards/{company}/jobs`, Lever
  `api.lever.co/v0/postings/{company}`, Workday's public job-board JSON) —
  configured per company slug the user wants to target; these are public,
  intentionally-machine-readable endpoints, so no ToS conflict.
- `LlmWebSearchJobSourceProvider` — calls `ModelRouterService` with an
  OpenRouter web-search-augmented model (or a dedicated search API like Exa/
  Tavily if the selected models don't expose native search) with a prompt
  built from the user's suggested roles + location, asking for a structured
  list of `{title, company, url, location, salary?}`. Results are treated as
  *candidate leads* — the enrichment stage still fetches/validates the actual
  posting at `applicationUrl` before it's scored, since LLM search results can
  hallucinate or go stale.
- `JobDiscoveryOrchestrator` fans out to all enabled providers for a user,
  normalizes, deduplicates (Req 3.3: normalized `title+company+location` hash
  OR source external id), and upserts into `job_listings`.
- Rate limiting: each provider records call counts in a lightweight
  `provider_usage` table (or cache-backed counter) checked against
  `config('services.<provider>.daily_limit')` before firing (Req 3.6).

### 3. `JobEnrichmentService`

- `EnrichJobDescription` queued job: given a `job_listings` row with a missing/
  short description, first attempts `Http::get($url)` + a DOM text extractor
  (using the existing DOMDocument/XPath approach, generalized into a
  `selectors.php` config list per known site instead of hard-coded in the
  controller).
- Checks `robots.txt` for the target domain (cached) before fetching; skips
  enrichment and logs if disallowed (Req 3.4c).
- Falls back to the Playwright automation worker only if plain fetch returns
  no content matching any known selector (mirrors the existing anti-detection
  approach in `scrapeAndUpdateJobDescription`, but as one reusable, queued,
  retryable implementation instead of three divergent ones).

### 4. `JobScoringService` (two-prompt pipeline)

```
ScoreJobListing job:
  1. promptFitAnalysis(jobDescription, userProfile) via ModelRouterService
       -> { requiredSkills[], seniority, matchedSkills[], missingSkills[], gapSummary }
  2. promptRatingDecision(fitAnalysis) via ModelRouterService
       -> { stars: 1-5, rationale, recommendedAction: 'auto_apply'|'store_only' }
  3. persist both raw + parsed outputs to job_scores
  4. if stars >= config('pipeline.auto_apply_star_threshold', 4)
       and user.auto_apply_enabled
     -> dispatch TailorResume
     else -> mark job_listings.pipeline_stage = 'store_only'
```

- Both prompts request strict JSON via OpenRouter structured outputs; on
  parse failure, one retry with a "return ONLY valid JSON matching this
  schema" corrective instruction, then fallback to `store_only` +
  `needs_review` flag (Req 5.5).
- Re-scoring creates a new `job_scores` row (versioned via `attempt_number`),
  never overwrites (Req 5.6).

### 5. `LatexRenderService`

```php
interface LatexRenderService
{
    public function renderResume(string $templateKey, array $content): RenderedDocument;
    public function renderCoverLetter(string $templateKey, array $content): RenderedDocument;
}

class RenderedDocument
{
    public string $texSource;
    public string $pdfPath; // local tmp path before S3 upload
    public bool $success;
    public ?string $compileError;
}
```

- Templates are Blade-like `.tex.blade.php` files (Laravel already supports
  custom Blade file extensions) using a non-conflicting delimiter set (LaTeX
  uses `{}`/`\` heavily, so templates use `@{{ }}` or a custom Blade
  compiler config to avoid collisions) rendered with tailored content, then
  piped to `tectonic <file>.tex` via `Symfony\Component\Process\Process`
  inside a queued job (`TailorResume`/`TailorCoverLetter`).
- LLM step (`ModelRouterService`, `taskType = 'resume_tailor'`) returns
  structured JSON (summary, ordered bullets per experience entry, emphasized
  skills list) — never raw LaTeX from the model — so template injection stays
  controlled and escaping (`\`, `%`, `&`, `#`, `_`, `$` must be LaTeX-escaped)
  is applied centrally in the renderer, not left to prompt instructions.
- Fabrication guard (Req 6.6): after generation, a lightweight check diffs
  employer names/titles/date ranges in the tailored output against the
  source `UserProfile` experience array; any token not found in source is
  flagged and the tailoring run is marked `needs_review` instead of proceeding
  straight to auto-apply.
- Retry-with-corrective-prompt loop on compile failure, bounded by
  `config('pipeline.latex_retry_max', 2)` (Req 6.4).

### 6. Storage (`S3StorageService`)

- Thin wrapper over Laravel's `Storage::disk('s3')`, adding: deterministic key
  builder (per Req 8.2 paths), signed URL generation
  (`Storage::disk('s3')->temporaryUrl($path, now()->addMinutes(15))`), and a
  `deleteForOwner($model)` helper wired into model `deleting` events for
  `resumes`/`applications` (Req 8.4).
- Requires adding `league/flysystem-aws-s3-v3` to `composer.json` and
  populating `AWS_*` env vars — both currently missing/empty.

### 7. `ApplyAdapter`s

```php
interface ApplyAdapter
{
    public function supports(string $applicationUrl): bool;
    public function apply(ApplyContext $context): ApplyResult;
}

class ApplyContext
{
    public JobListing $job;
    public User $user;
    public UserProfile $profile;
    public string $tailoredResumePath;  // S3 key
    public ?string $coverLetterPath;    // S3 key, nullable
}

class ApplyResult
{
    public string $status; // 'applied' | 'failed' | 'needs_review'
    public ?string $failureReason;
    public array $screenshotPaths; // S3 keys, for audit trail
    public array $unansweredQuestions; // surfaced to frontend if needs_review
}
```

- `GreenhouseApplyAdapter`, `LeverApplyAdapter`, `WorkdayApplyAdapter`: each
  drives the Playwright automation worker with a platform-specific form-fill
  script (field selectors are stable per ATS since they're standardized
  products, unlike arbitrary company career pages).
- `LinkedInEasyApplyAdapter`: implements the same interface but is only
  registered/enabled when `user.linkedin_auto_apply_opt_in = true`; applies a
  stricter per-day cap (`config('pipeline.linkedin_daily_cap', 5)` vs. a
  higher ATS default) and prepends a mandatory risk-acknowledgment step in the
  frontend before the toggle can be enabled (Req 9.3).
- All adapters: on CAPTCHA/login-wall/bot-detection page signatures, abort
  immediately and return `needs_review` — never attempt bypass (Req 9.6).
- `SubmitApplication` queued job selects the first adapter whose `supports()`
  matches the job's `application_url` domain, defaulting to `needs_review`
  with a "no adapter available, apply manually" note if none match (i.e. most
  arbitrary company career-page URLs fall back to manual apply rather than a
  best-effort/fragile generic form-filler — a deliberate scope limit for
  reliability).

## Data Models

Additive migrations (new tables) plus targeted changes to existing ones.

**Schema management convention (Requirement 1C):** every table below is
created or altered through its own dedicated migration file, generated with
`artisan make:migration`, following the one-table-per-migration convention —
not by editing the existing multi-table
`2025_03_11_083440_create_user_profiles_table.php` file (that file's already-
applied history is left untouched) and not via manual SQL. Concretely:

- New tables (`job_scores`, `tailored_documents`, `model_usage_logs`,
  `provider_usage`, `user_automation_settings`) each get their own
  `create_{table}_table` migration, generally paired with `artisan make:model
  {Model} -m`.
- Column additions to existing tables (`resumes`, `job_listings`,
  `applications`) each get their own `add_{columns}_to_{table}_table`
  migration.
- The `resume_uploads` → `resumes` consolidation is done via a migration with
  both `up()` (backfill data, then drop `resume_uploads`) and `down()`
  (recreate `resume_uploads` schema; note if data restoration is intentionally
  not reversible).
- `admin_resume.sql` (the checked-in phpMyAdmin dump) is not used for setup
  going forward; any local dev seed data is expressed as a
  `database/seeders/*Seeder.php` class instead.

### Modified existing tables

```
resumes (consolidating resume_uploads into this table; drop resume_uploads)
  - add: storage_disk (string, default 's3')
  - add: status (enum: uploaded, parsing, parsed, failed)
  - fix: cast parsed_data, job_analysis to array (currently missing)

job_listings
  - add: source_key (string) -- 'adzuna' | 'jsearch' | 'greenhouse' | 'llm_search'
  - add: dedupe_hash (string, indexed) -- normalized title+company+location
  - add: pipeline_stage (enum: discovered, enriching, scored, tailoring,
         tailored, applying, applied, failed, needs_review, store_only)
  - add: salary_min, salary_max, currency (nullable)

applications
  - fix: $table visibility bug (private -> protected)
  - add: tailored_cover_letter_id (nullable FK)
  - add: apply_adapter_used (string, nullable)
  - add: automation_log (json, nullable) -- steps taken, screenshots, errors
```

### New tables

```
job_scores
  id, job_listing_id (FK), user_id (FK), attempt_number (int),
  fit_analysis (json), stars (tinyint), rationale (text),
  recommended_action (enum: auto_apply, store_only),
  raw_prompt_1_output (text), raw_prompt_2_output (text),
  created_at, updated_at
  index: (job_listing_id, user_id, attempt_number)

tailored_documents  (replaces/repurposes the unused `tailored_resumes` table)
  id, user_id (FK), job_listing_id (FK), application_id (nullable FK),
  type (enum: resume, cover_letter), template_key (string),
  s3_path (string), tex_source_s3_path (nullable string),
  generation_model (string), generation_tier (string),
  fabrication_flags (json, nullable), status (enum: pending, rendered, failed),
  created_at, updated_at

model_usage_logs
  id, task_type (string), model_used (string), tier_used (string),
  input_tokens (int), output_tokens (int), estimated_cost_usd (decimal),
  related_type (string, nullable), related_id (bigint, nullable), -- polymorphic
  created_at

provider_usage
  id, provider_key (string), user_id (nullable FK), call_count (int),
  window_date (date), unique: (provider_key, user_id, window_date)

user_automation_settings
  id, user_id (FK, unique), auto_apply_enabled (bool, default false),
  auto_apply_star_threshold (tinyint, default 4),
  linkedin_auto_apply_opt_in (bool, default false),
  daily_apply_cap (int, default 20), linkedin_daily_apply_cap (int, default 5),
  created_at, updated_at
```

### Removed / retired

- `resume_uploads` table and `ResumeUpload` model (consolidated into
  `resumes`).
- `Jobs` model, `JobsController`, and the `jobs`-resource routes (already
  commented out) — retired to avoid confusion with Laravel's queue `jobs`
  table.
- `ollama.py` Flask sidecar — retired once `ModelRouterService`/OpenRouter
  fully replaces the local-Ollama call path in `AnalyzeResumeJob`.

## API Surface (new `routes/api.php`, Sanctum-protected)

```
POST   /api/auth/login | /api/auth/register | /api/auth/logout
GET    /api/me

POST   /api/resumes                  (upload, replaces ResumeController/ResumeUploadController)
GET    /api/resumes | /api/resumes/{id}
DELETE /api/resumes/{id}

GET    /api/profile                  PATCH /api/profile

GET    /api/jobs                     (filter by pipeline_stage, source, rating)
GET    /api/jobs/{id}
POST   /api/jobs/discover            (manual trigger of JobDiscoveryOrchestrator)
POST   /api/jobs/{id}/rescore
POST   /api/jobs/{id}/tailor         (manual tailoring trigger for store_only jobs)
POST   /api/jobs/{id}/apply          (manual apply trigger)

GET    /api/applications | /api/applications/{id}
PATCH  /api/applications/{id}        (manual status updates, distinct from automation-driven ones)

GET    /api/automation-settings      PATCH /api/automation-settings
GET    /api/model-usage              (cost dashboard, optional admin view)
```

All list endpoints paginate (Laravel's built-in paginator) and return
signed S3 URLs for any file field rather than raw storage paths.

## Error Handling

- **LLM call failures** (timeout, malformed JSON, rate limit): handled inside
  `ModelRouterService` with fallback-model retry, then bubble a typed
  `ModelRouterException` that calling jobs catch to mark their stage `failed`
  with a stored reason — never an uncaught exception that silently stalls a
  chain.
- **LaTeX compile failures**: captured `stderr` from the `tectonic` process is
  stored on the `tailored_documents.fabrication_flags`/a `compile_error`
  column for debugging, with bounded corrective retries (Req 6.4).
- **Scraping/enrichment blocks** (Access Denied, CAPTCHA): existing detection
  logic (`Access Denied`/`Blocked` title checks) is preserved and generalized;
  on detection, enrichment marks the job `needs_review` for description
  instead of leaving it silently empty.
- **Apply automation failures**: every `ApplyAdapter::apply()` call is wrapped
  so exceptions convert into `ApplyResult(status: 'failed', failureReason:
  ...)` rather than crashing the queue worker; screenshots are captured on
  failure for user-facing debugging (mirrors the existing screenshot-on-
  Access-Denied pattern in `scrapeAndUpdateJobDescription`).
- **Provider rate-limit/budget exhaustion**: `JobDiscoveryOrchestrator` checks
  `provider_usage` before calling a provider and skips it (logging a notice)
  rather than failing the whole discovery run for one exhausted provider.
- **Queue-wide failures**: rely on Laravel's `failed_jobs` table; a scheduled
  command (or Horizon, if adopted) surfaces failed pipeline jobs count in the
  frontend dashboard so failures are visible, not just logged.

## Testing Strategy

### Unit tests
- `ModelRouterService`: tier selection given salary thresholds vs. complexity
  thresholds (table-driven cases), fallback-model retry behavior (mocked
  OpenRouter client), usage logging correctness.
- `JobSourceProvider` implementations: response normalization from fixture
  payloads (recorded Adzuna/JSearch/Greenhouse JSON samples) into
  `NormalizedJob`, dedupe-hash generation.
- `JobScoringService`: JSON parsing/retry/fallback logic with mocked model
  responses (valid JSON, malformed JSON, empty response).
- `LatexRenderService`: template injection escaping (verify LaTeX special
  characters in user/job data don't break compilation or enable injection),
  fabrication-flag diffing logic.
- Model fixes: `resumes` array casts, `Application::$table` visibility.

### Feature/integration tests
- Full resume upload → parse → profile update flow (mocking the OpenRouter
  HTTP call) against a real test database.
- Discovery → enrichment → scoring → tailoring chain using fake queue +
  mocked HTTP for all external providers (Adzuna/JSearch/ATS/OpenRouter),
  asserting correct `pipeline_stage` transitions and that a 5-star job reaches
  `tailoring`/`applied` while a 2-star job stops at `store_only`.
- API auth/CORS: verify Sanctum bearer-token issuance/validation flow, 401 on
  expired/invalid token, CORS restricted to configured frontend origin(s).
- S3 storage: use a local S3-compatible test double (e.g. MinIO in CI, or
  Laravel's `Storage::fake('s3')`) to verify key structure and signed URL
  generation without hitting real AWS in tests.

### Apply-adapter tests
- Each `ApplyAdapter` tested against recorded fixture HTML/DOM for its target
  ATS (Greenhouse/Lever/Workday sample application forms) run through the
  Playwright worker in a controlled test harness — no tests run against live
  third-party sites in CI.
- Explicit test asserting `LinkedInEasyApplyAdapter` is not registered/invoked
  unless `linkedin_auto_apply_opt_in = true`.

### Manual/E2E validation (not automatable safely)
- One real end-to-end run against a small number of real Greenhouse/Lever
  postings in a sandbox/test account before enabling auto-apply broadly.
- Manual review of a sample of tailored resumes for fabrication before trusting
  the automated fabrication-guard threshold.

## Correctness Properties

Property 1: **Provider isolation**
*For any* new `JobSourceProvider` added to the system, no changes are required
in `JobScoringService`, `LatexRenderService`, or `ApplyAdapter` code — all
providers normalize into `NormalizedJob`/`job_listings`.
**Validates: Requirement 3.2**

Property 2: **Auto-apply gating**
*For any* scored job, the job SHALL enter the tailoring/apply pipeline
if-and-only-if `stars >= auto_apply_star_threshold` AND
`user_automation_settings.auto_apply_enabled = true`; otherwise it SHALL be
`store_only`.
**Validates: Requirements 5.2, 5.3, 9.1**

Property 3: **LinkedIn adapter opt-in isolation**
*For any* application attempt where `applicationUrl` resolves to LinkedIn,
`LinkedInEasyApplyAdapter` SHALL only execute if
`user_automation_settings.linkedin_auto_apply_opt_in = true`; all other
adapters are unaffected by this flag.
**Validates: Requirement 9.3**

Property 4: **No fabricated content reaches auto-apply undetected**
*For any* tailored resume/cover letter whose generated employer/title/date
tokens don't match the source `UserProfile` experience array, the associated
`tailored_documents`/`applications` row SHALL be flagged `needs_review` and
SHALL NOT proceed to automated submission.
**Validates: Requirement 6.6, 9.4**

Property 5: **Storage path determinism**
*For any* stored resume/tailored-resume/cover-letter file, its S3 key SHALL
follow the documented `users/{user_id}/...` structure and SHALL be
retrievable via a generated signed URL without requiring the object to be
public.
**Validates: Requirements 8.2, 8.3**

Property 6: **Model usage completeness**
*For any* completed or failed OpenRouter call made through
`ModelRouterService`, exactly one corresponding `model_usage_logs` row SHALL
exist recording model, tier, tokens, and cost.
**Validates: Requirement 4.6**

Property 7: **Pipeline stage monotonicity**
*For any* `job_listings` row, `pipeline_stage` transitions SHALL only move
forward along the defined chain (`discovered → enriching → scored →
[tailoring → tailored → applying → applied|failed] | needs_review |
store_only`) or terminate in a failure/review state — never silently revert to
an earlier stage without an explicit user-triggered re-run (Requirement 5.6,
9.8).
**Validates: Requirements 3, 5, 9, 11**

## Open Decisions for the User

1. **Playwright/Node automation worker vs. keeping Panther/PHP** for apply
   automation — recommended: Playwright (Node), given Panther's dev-only
   status and weaker interaction ergonomics. Confirm before task 6/9
   implementation begins.
2. **Which second job-aggregator to integrate** (JSearch via RapidAPI vs. an
   alternative) — needs an actual RapidAPI account/key decision.
3. **LaTeX engine availability** in the target deployment host — confirm
   `tectonic` can be installed there, or that `pdflatex` is acceptable.
4. **LinkedIn auto-apply feature at all** — given the ToS risk discussed in
   requirements.md, confirm you still want `LinkedInEasyApplyAdapter` built
   (even opt-in) versus scoping auto-apply to ATS-direct platforms only for
   v1 and revisiting LinkedIn later.
5. **Frontend framework/tooling choice** — this design assumes Vite + React +
   React Router + TanStack Query for `frontend/`, mirroring the original
   plan, but since `frontend/` is now a fully independent project, Next.js or
   another React toolchain is equally viable. Confirm before task 3 scaffolds
   the project so the choice isn't made by default.
6. **Sanctum token storage strategy on the frontend** — plain `localStorage`
   is simplest but exposes the token to any injected/XSS script; a backend-set
   httpOnly cookie (still validated as a bearer-equivalent by a custom
   Sanctum guard) is safer but more work to wire up cross-origin (`SameSite`/
   `secure` cookie attributes, credentials-mode fetch config). Confirm which
   tradeoff is acceptable before task 2 implements the auth endpoints.

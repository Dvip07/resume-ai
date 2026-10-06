# Implementation Plan: Job Automation Platform (Decoupled Backend + Frontend)

Notes:
- Tasks are ordered so the app stays functional throughout (strangler
  pattern, two phases — see design.md "Migration strategy"):
  - **Phase A** (tasks 2-3): build real `/api/*` endpoints inside the
    *current* single-repo Laravel app, alongside Blade, using Sanctum bearer
    tokens (not SPA cookies, since the frontend will not be same-origin).
  - **Phase B** (task 4 onward): move the Laravel app into `backend/`,
    scaffold `frontend/` as an independent React project, and build all
    remaining feature work directly against the two-folder structure.
- `_Requirements: X.Y_` references trace back to requirements.md.
- Open decisions listed at the end of design.md (frontend toolchain, token
  storage strategy, Playwright vs Panther, second aggregator choice, LaTeX
  engine, LinkedIn adapter scope) should be confirmed before tasks 3, 4, 8,
  11, and 15 respectively.
- Task 1 is complete (see below) — do not redo it.

- [x] 1. Foundation: fix known bugs, enforce migration-based schema, consolidate duplicate models
  - [x] 1.1 Fix `Application::$table` visibility (`private` → `protected`)
    - _Requirements: 1.8_
  - [x] 1.2 Add missing `array` casts to `Resume::$casts` (`parsed_data`,
        `job_analysis`) — model-only change, no migration needed since the
        columns already exist as `json` in the schema
    - _Requirements: 1.8, 2.3_
  - [x] 1.3 Generate `add_storage_disk_and_status_to_resumes_table` migration
        (`artisan make:migration`) adding `storage_disk`, `status` columns to
        `resumes`; generate a separate
        `consolidate_resume_uploads_into_resumes_table` migration that
        backfills existing `resume_uploads` rows into `resumes` and drops
        `resume_uploads` (reversible `down()`, or explicitly documented as
        non-reversible for data restoration); leave the original
        `2025_03_11_083440_create_user_profiles_table.php` untouched; remove
        `ResumeUpload` model/controller after migration runs successfully
    - _Requirements: 1C.1, 1C.2, 1C.3, 1C.4, 2.1_
  - [x] 1.4 Retire `Jobs` model, `JobsController`, and commented-out
        `jobs`-resource routes in `routes/web.php` (no migration needed —
        the legacy `Jobs` model was never backed by its own table)
    - _Requirements: 12.3_
  - [x] 1.5 Unify `AnalyzeResumeJob` to accept the consolidated `Resume`
        model consistently (fix the current type-hint mismatch)
    - _Requirements: 2.1, 2.2_
  - [x] 1.6 Stop relying on `admin_resume.sql` for setup; port any needed
        local dev seed data into a `database/seeders/*Seeder.php` class
    - _Requirements: 1C.5_

## Phase A — Build the API in the current single-repo app (pre-split)

- [x] 2. Laravel API foundation (Sanctum bearer-token auth)
  - [x] 2.1 Install/confirm `laravel/sanctum` is configured for API tokens
        (not the SPA/`stateful` cookie guard); configure `config/cors.php`
        with an explicit allowed-origins list (no wildcard) even though the
        frontend doesn't exist yet, anticipating its dev URL
        (e.g. `http://localhost:5173`)
    - _Requirements: 1.5, 1.9_
  - [x] 2.2 Build `Api\AuthController` (login issues a Sanctum personal
        access token, register, logout revokes the token, `me`), alongside
        existing `LoginRegistrationController` (do not remove yet)
    - _Requirements: 1.5, 1.6, 12.1_
  - [x] 2.3 Write feature tests: login returns a token, `/api/me` returns 401
        without a valid `Authorization: Bearer` header, 200 with user when a
        valid token is presented
    - _Requirements: 1.5, 1.6_

- [x] 3. Build out remaining `/api/*` endpoints against Blade-era logic
  - [x] 3.1 Build `Api\ResumeController` (store/index/show/destroy) reusing
        upload/parse logic from `ResumeController`
    - _Requirements: 2.1, 2.4_
  - [x] 3.2 Build `Api\ProfileController` (get/patch) for manual profile
        edits
    - _Requirements: 2.5_
  - [x] 3.3 Build `Api\JobListingController` (index/show) wrapping the
        current Adzuna-backed logic, `Api\ApplicationController`
        (index/show) as a thin read layer over existing data
    - _Requirements: 10.1, 10.2_
  - [x] 3.4 Confirm the full `/api/*` surface has parity with what a
        frontend needs for auth, resumes, profile, jobs, applications before
        moving to Phase B — this is the checkpoint that de-risks the folder
        split (Requirement 12.1)
    - _Requirements: 12.1_

## Phase B — Split into `backend/` + `frontend/`

- [x] 4. Move the Laravel app into `backend/`
  - [x] 4.1 Confirm open decision on frontend toolchain (Vite+React vs
        Next.js vs other) before scaffolding `frontend/`
    - _Requirements: (design decision)_
  - [x] 4.2 Use `smart_relocate`/git-aware moves to relocate all Laravel
        application files (`app/`, `bootstrap/`, `config/`, `database/`,
        `public/`, `resources/`, `routes/`, `storage/`, `tests/`, `artisan`,
        `composer.json`, `composer.lock`, `phpunit.xml`, `.env`,
        `.env.example`, `vite.config.js`, `package.json` if backend-only
        assets remain there, `server.php`) into `backend/`, preserving git
        history where possible
    - _Requirements: 1.1_
  - [x] 4.3 Update any absolute-path assumptions broken by the move (e.g.
        `base_path('drivers/chromedriver')` in
        `JobListingController::scrapeAndUpdateJobDescription`, storage
        symlinks, `.htaccess`, deployment scripts) and re-run
        `composer install`, `php artisan migrate`, `php artisan serve` from
        within `backend/` to confirm the app still boots and passes existing
        tests from its new location
    - _Requirements: 1.1, 1.2_
  - [x] 4.4 Remove any frontend-facing dependencies that were only present
        for the abandoned in-Laravel SPA plan (`laravel-vite-plugin` React
        additions, if any were added in Phase A) — `backend/package.json`
        (if kept at all, e.g. for asset compilation of remaining Blade auth
        views) SHALL have no React/React Router/TanStack Query dependencies
    - _Requirements: 1B.3_
  - [x] 4.5 Update root-level docs (`README.md`) to point to
        `backend/README.md` and `frontend/README.md` for setup instructions
    - _Requirements: 1B.1_

- [x] 5. Scaffold `frontend/` as an independent React project
  - [x] 5.1 `npm create vite@latest frontend -- --template react-ts` (or
        equivalent per the confirmed toolchain decision) at the repo root,
        fully independent `package.json`/lockfile — no shared
        `node_modules` with `backend/`
    - _Requirements: 1.1, 1B.3_
  - [x] 5.2 Add React Router, TanStack Query (or confirmed equivalents), and
        an API client module (`frontend/src/api/client.ts`) that reads
        `VITE_API_BASE_URL` from env and attaches the stored bearer token to
        every request
    - _Requirements: 1.3, 1.5_
  - [x] 5.3 Add `frontend/.env` / `.env.local` with `VITE_API_BASE_URL`
        defaulted to the backend's local dev URL (e.g.
        `http://localhost:8000/api`); document overriding it per environment
    - _Requirements: 1B.2_
  - [x] 5.4 Confirm token-storage decision (open decision #6) and implement
        auth state: store token on login, attach as `Authorization: Bearer`
        header, clear + redirect to login on 401
    - _Requirements: 1.5, 1.6_
  - [x] 5.5 Build Login/Register screens calling `backend/`'s `/api/auth/*`
        endpoints; wire a protected-route wrapper
    - _Requirements: 1.4, 1.6_
  - [x] 5.6 Build Dashboard shell (nav, layout) as the frontend's own
        component tree — not reusing any Blade layout markup
    - _Requirements: 1.4_
  - [x] 5.7 Verify CORS end-to-end: `frontend/` dev server → `backend/` API
        succeeds with the configured allowed-origins list, and fails
        (as expected) from an unlisted origin
    - _Requirements: 1.9_

- [x] 6. Migrate resumes feature to `frontend/` + finalize `backend/` API
  - [x] 6.1 Extend `Api\ResumeController::store` to write to the `s3` disk
        (add `league/flysystem-aws-s3-v3` to `backend/composer.json`,
        populate `AWS_*` env vars in `backend/.env`) instead of local
        `public` disk
    - _Requirements: 2.1, 8.1, 8.2_
  - [x] 6.2 Add `resumes.status` transitions (`uploaded → parsing → parsed
        | failed`) surfaced via API; update `AnalyzeResumeJob` to set status
    - _Requirements: 2.4_
  - [x] 6.3 Build `frontend/` Resume upload/list/detail screens; show
        parsing status and errors
    - _Requirements: 2.4, 2.5_
  - [x] 6.4 Build `frontend/` Profile screen with editable skills/location/
        links/experience/education, calling `Api\ProfileController`
    - _Requirements: 2.5_
  - [x] 6.5 Cut over: remove `ResumeController`'s Blade view methods/views
        in `backend/` once the `frontend/` resume flow is verified
    - _Requirements: 12.2_

- [x] 7. OpenRouter `ModelRouterService` (backend only — no frontend surface yet)
  - [x] 7.1 Add `openrouter` config block to `backend/config/services.php`
        (`OPENROUTER_API_KEY` env, tier→model-list mapping, salary/complexity
        thresholds); confirm current model slugs/pricing at
        `https://openrouter.ai/models` before finalizing defaults
    - _Requirements: 4.1, 4.2, 4.7_
  - [x] 7.2 Implement `ModelRouterService::complete()` with HTTP client,
        JSON-schema/structured-output request support, fenced-JSON fallback
        parser (reuse pattern from `AnalyzeResumeJob::processOllamaResponse`)
    - _Requirements: 4.1_
  - [x] 7.3 Implement tier selection: salary-based thresholds when job
        salary data present, else complexity-score heuristic (JD length,
        requirement count, skill-gap size)
    - _Requirements: 4.3, 4.4_
  - [x] 7.4 Implement fallback-model retry within a tier on failure/timeout
    - _Requirements: 4.5_
  - [x] 7.5 Generate `model_usage_logs` via `artisan make:model ModelUsageLog
        -m`; log every call (success and failure) with tokens/cost/task
        type/related record
    - _Requirements: 4.6, 1C.1, 1C.7_
  - [x] 7.6 Unit tests: tier selection table-driven cases, fallback retry,
        usage logging
    - _Requirements: 4.2-4.6_
  - [x] 7.7 Migrate `AnalyzeResumeJob`'s resume-parsing prompt to
        `ModelRouterService` (task type `resume_parse`), retire `ollama.py`
        sidecar dependency once verified working
    - _Requirements: 2.2, 12.3_

- [x] 8. Job source providers & discovery orchestration (backend)
  - [x] 8.1 Define `JobSourceProvider` interface and `NormalizedJob` DTO
    - _Requirements: 3.1, 3.2_
  - [x] 8.2 Refactor existing Adzuna call in `JobListingController::index`/
        `Api\JobListingController` into `AdzunaJobSourceProvider`
    - _Requirements: 3.1_
  - [x] 8.3 Implement `JSearchJobSourceProvider` (or chosen alternative
        aggregator — confirm open decision #2) replacing dead RapidAPI
        LinkedIn code
    - _Requirements: 3.1_
  - [x] 8.4 Implement `AtsBoardJobSourceProvider` for Greenhouse and Lever
        public job-board JSON APIs, configurable per company slug
    - _Requirements: 3.1_
  - [x] 8.5 Implement `LlmWebSearchJobSourceProvider` using
        `ModelRouterService` with a search-capable OpenRouter model (or Exa/
        Tavily if needed), treating results as unverified leads
    - _Requirements: 3.1_
  - [x] 8.6 Generate `add_source_key_dedupe_hash_pipeline_stage_salary_to_job_listings_table`
        migration (`artisan make:migration`) adding `source_key`,
        `dedupe_hash`, `pipeline_stage`, `salary_min/max/currency` to
        `job_listings` — a column-addition migration, not an edit to the
        original `create_user_profiles_table.php`
    - _Requirements: 3.3, 4.3, 1C.2, 1C.3_
  - [x] 8.7 Implement `JobDiscoveryOrchestrator`: fan-out to enabled
        providers, dedupe via `dedupe_hash`/external id, upsert
        `job_listings`, set `pipeline_stage = discovered`
    - _Requirements: 3.2, 3.3_
  - [x] 8.8 Generate `provider_usage` via `artisan make:model ProviderUsage
        -m`; add per-provider daily-limit check before each provider call
    - _Requirements: 3.6, 1C.1, 1C.7_
  - [x] 8.9 Create `DiscoverJobsForUser` queued job + scheduled command
        (`jobs:discover`) replacing synchronous fetch in
        `JobListingController::index`
    - _Requirements: 11.1_
  - [x] 8.10 Unit tests: provider normalization from fixture payloads,
        dedupe-hash correctness

- [x] 9. Job description enrichment (backend)
  - [x] 9.1 Build `JobEnrichmentService`: plain HTTP fetch + DOM selector
        extraction first (generalize existing selector list from
        `scrapeAndUpdateJobDescription` into config), robots.txt check
        before fetch
    - _Requirements: 3.4_
  - [x] 9.2 Decide and stand up browser-automation path (Playwright/Node
        worker per open decision #1, or retained Panther) for enrichment
        fallback when plain fetch yields no content
    - _Requirements: 3.4_
  - [x] 9.3 Consolidate the three existing scraping implementations
        (`JobListingController::scrapeAndUpdateJobDescription`,
        `UpdateJobDescriptions` command, `App\Jobs\UpdateJobDescription`)
        into one queued `EnrichJobDescription` job; delete the other two
    - _Requirements: 3.4, 12.3_
  - [x] 9.4 Chain: `DiscoverJobsForUser` → dispatch `EnrichJobDescription`
        for jobs with missing/short descriptions → set
        `pipeline_stage = scored` pending stage transition after scoring
    - _Requirements: 11.3_

- [x] 10. Two-prompt scoring pipeline (backend)
  - [x] 10.1 Generate `job_scores` via `artisan make:model JobScore -m`
    - _Requirements: 5.4, 1C.1, 1C.7_
  - [x] 10.2 Implement `JobScoringService::promptFitAnalysis()` and
        `promptRatingDecision()` via `ModelRouterService`, with retry +
        stricter-format-instruction fallback on parse failure
    - _Requirements: 5.1, 5.5_
  - [x] 10.3 Implement `ScoreJobListing` queued job: run both prompts,
        persist `job_scores`, branch `pipeline_stage` to `tailoring` (if
        stars ≥ threshold and `auto_apply_enabled`) or `store_only`
    - _Requirements: 5.2, 5.3_
  - [x] 10.4 Support re-scoring: new `job_scores` row per `attempt_number`,
        never overwrite
    - _Requirements: 5.6_
  - [x] 10.5 Generate `user_automation_settings` via `artisan make:model
        UserAutomationSetting -m` (`auto_apply_enabled`,
        `auto_apply_star_threshold`, `linkedin_auto_apply_opt_in`, daily caps)
    - _Requirements: 5.2, 9.1, 9.3, 9.7, 1C.1, 1C.7_
  - [x] 10.6 Unit + feature tests: JSON parse success/failure/retry paths;
        end-to-end discover→enrich→score chain with mocked HTTP asserting
        correct `pipeline_stage` branching
    - _Requirements: 5.1-5.6_

- [x] 11. LaTeX resume tailoring (backend)
  - [x] 11.1 Confirm LaTeX engine availability in deployment target (open
        decision #3); install `tectonic` (or document `pdflatex` fallback)
    - _Requirements: 6.2_
  - [x] 11.2 Build base LaTeX resume template(s) as `.tex.blade.php` with a
        non-conflicting Blade delimiter configuration
    - _Requirements: 6.1, 6.3_
  - [x] 11.3 Implement `LatexRenderService::renderResume()`: call
        `ModelRouterService` (task type `resume_tailor`) for structured
        tailored content, LaTeX-escape all injected values, render `.tex`,
        compile via `tectonic` in a queued job, capture compile errors
    - _Requirements: 6.1, 6.2_
  - [x] 11.4 Implement compile-failure retry loop with corrective prompt,
        bounded by `config('pipeline.latex_retry_max')`
    - _Requirements: 6.4_
  - [x] 11.5 Implement fabrication guard: diff generated employer/title/date
        tokens against `UserProfile.experience`; flag `needs_review` on
        mismatch
    - _Requirements: 6.6, 9.4_
  - [x] 11.6 Generate a dedicated `rename_tailored_resumes_to_tailored_documents_table`
        migration (via `artisan make:migration`) to rename/repurpose the
        existing unused `tailored_resumes` table plus add its new columns,
        and `artisan make:model TailoredDocument` for the model (no `-m`
        needed since the migration is hand-authored as a rename+alter, not a
        fresh create)
    - _Requirements: 6.5, 1C.2_
  - [x] 11.7 Implement `TailorResume` queued job: render → upload to S3 (key
        structure per design.md) → persist `tailored_documents` row → link to
        `job_listings`/pending `applications` row
    - _Requirements: 6.5, 8.1, 8.2, 8.3_
  - [x] 11.8 Unit tests: LaTeX-escaping correctness, fabrication-guard
        diffing logic, template selection

- [x] 12. Cover letter tailoring (backend)
  - [x] 12.1 Build base LaTeX cover-letter template
    - _Requirements: 7.1_
  - [x] 12.2 Implement detection of whether a job posting requires/requests
        a cover letter (from enriched JD text, keyword/LLM classification)
    - _Requirements: 7.2_
  - [x] 12.3 Implement `LatexRenderService::renderCoverLetter()` +
        `TailorCoverLetter` queued job, only dispatched when detection says
        required; same retry/fallback behavior as resume tailoring
    - _Requirements: 7.1, 7.3, 7.4_

- [x] 13. S3 storage finalization (backend)
  - [x] 13.1 Implement `S3StorageService`: deterministic key builder, signed
        URL generation, `deleteForOwner()` wired to `resumes`/`applications`
        model deletion events
    - _Requirements: 8.2, 8.3, 8.4_
  - [x] 13.2 Add upload retry-with-backoff for all S3 writes in tailoring/
        upload jobs; do not mark stage complete until upload succeeds
    - _Requirements: 8.5_
  - [x] 13.3 Tests using `Storage::fake('s3')` (or MinIO in CI) verifying key
        structure, signed URL generation, deletion-on-model-delete

- [x] 14. Applications tracking (backend API + frontend dashboard)
  - [x] 14.1 Generate `add_tailored_cover_letter_id_apply_adapter_automation_log_to_applications_table`
        migration (`artisan make:migration`) adding
        `tailored_cover_letter_id`, `apply_adapter_used`, `automation_log`
    - _Requirements: 9.5, 1C.2_
  - [x] 14.2 Extend `Api\ApplicationController` with patch for manual status
        updates, distinct from automation-driven status
    - _Requirements: 10.1, 10.4_
  - [x] 14.3 Build `frontend/` Applications dashboard: list with
        pipeline_stage + rating, detail view (JD, score rationale, signed
        document links, automation log), `needs_review` call-to-action
        surfacing
    - _Requirements: 10.1, 10.2, 10.3_

- [x] 15. Automated application submission (backend)
  - [x] 15.1 Confirm automation-worker approach (open decision #1:
        Playwright/Node vs. Panther) before building adapters
    - _Requirements: (design decision)_
  - [x] 15.2 Define `ApplyAdapter` interface, `ApplyContext`/`ApplyResult`
        DTOs
    - _Requirements: 9.1, 9.2_
  - [x] 15.3 Implement `GreenhouseApplyAdapter` (form-fill via automation
        worker: name/email/phone/resume upload/cover letter upload/standard
        screening questions)
    - _Requirements: 9.2_
  - [x] 15.4 Implement `LeverApplyAdapter` and `WorkdayApplyAdapter`
        following the same pattern
    - _Requirements: 9.2_
  - [x] 15.5 Implement unanswered-question detection: pause + mark
        `needs_review` with the specific unanswered fields surfaced, rather
        than guessing
    - _Requirements: 9.4_
  - [x] 15.6 Implement CAPTCHA/login-wall/bot-detection abort logic (reuse
        existing Access-Denied detection pattern), never attempting bypass
    - _Requirements: 9.6_
  - [x] 15.7 Implement `SubmitApplication` queued job: select first
        supporting adapter by `application_url` domain, default to
        `needs_review` "apply manually" when no adapter matches
    - _Requirements: 9.1, 9.5_
  - [x] 15.8 Implement per-day/per-platform apply caps via
        `provider_usage`-style counters checked before each attempt
    - _Requirements: 9.7_
  - [x] 15.9 (Optional, confirm open decision #4 before building) Implement
        `LinkedInEasyApplyAdapter`, gated strictly on
        `linkedin_auto_apply_opt_in`, with frontend risk-acknowledgment flow
        and stricter daily cap
    - _Requirements: 9.3_
  - [x] 15.10 Chain: `TailorResume`/`TailorCoverLetter` success → dispatch
        `SubmitApplication`; wire the full discover→enrich→score→tailor→apply
        chain end to end
    - _Requirements: 11.3_
  - [x] 15.11 Adapter tests against recorded fixture HTML for each ATS in a
        controlled harness (no live third-party requests in CI); explicit
        test that `LinkedInEasyApplyAdapter` never runs without opt-in

- [ ] 16. Production infrastructure, observability & final cutover
  - [x] 16.1 Document and configure Supervisor-managed `queue:work` (or
        adopt Laravel Horizon) for `backend/` in production
    - _Requirements: 11.4_
  - [x] 16.2 Surface `failed_jobs` counts / pipeline failures in the
        `frontend/` dashboard (simple admin/status widget)
    - _Requirements: 11.2_
  - [x] 16.3 Add scheduled command(s) for periodic discovery
        (`jobs:discover`) via `backend/app/Console/Kernel.php`
    - _Requirements: 3.1, 11.1_
  - [x] 16.4 Migrate remaining Blade areas (Dashboard, Jobs listing/detail,
        remaining UserProfile screens) to `frontend/` + API equivalents,
        removing the corresponding Blade controllers/views in `backend/`
        once each is verified
    - _Requirements: 1.4, 12.2_
  - [~] 16.5 Decide and implement final auth screen approach (frontend-hosted
        vs. retained server-rendered login in `backend/`) — make explicit
        per Requirement 1.7/12.4
    - _Requirements: 1.7, 12.4_
  - [~] 16.6 Remove `ollama.py` sidecar and any remaining references once
        `ModelRouterService` fully covers resume parsing
    - _Requirements: 12.3_
  - [~] 16.7 Final sweep: confirm no Blade view in `backend/` drives
        authenticated functionality (Requirement 1.7/12.4), confirm
        `backend/` has zero frontend-framework dependencies and `frontend/`
        has zero PHP dependencies (Requirement 1B.3), remove dead code
    - _Requirements: 1.7, 1B.3, 12.4_
  - [~] 16.8 Set up independent CI build/test steps for `backend/` (PHPUnit)
        and `frontend/` (its own test runner, e.g. Vitest)
    - _Requirements: 1B.4_

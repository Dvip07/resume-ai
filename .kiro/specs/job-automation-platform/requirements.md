# Requirements Document: Job Automation Platform (Decoupled Backend + Frontend)

## Introduction

This spec covers rebuilding "resume-ai" from a server-rendered Laravel/Blade
monolith into **two independent, separately-developed and separately-
deployable projects living in their own top-level folders**:

- `backend/` — a Laravel API (JSON only, no Blade views for authenticated app
  functionality).
- `frontend/` — a standalone React application (its own `package.json`, build
  tooling, and dev server), built and run independently of the backend.

The two communicate exclusively over a versioned HTTP/JSON REST API — the
frontend never shares a build pipeline, `node_modules`, or process with the
backend, and the backend never renders the frontend's UI. This is a stronger
separation than a same-origin SPA embedded in Laravel's Vite build: each side
has its own dependency lifecycle, its own dev/build/deploy commands, and can
be deployed to different hosts entirely.

This spec also extends the platform into a full job-automation pipeline:

1. Discover jobs from multiple sources (job APIs, ATS boards, and LLM-powered
   web search) instead of the current single Adzuna integration and abandoned
   LinkedIn/RapidAPI code.
2. Score each job description with an LLM against the user's profile (a
   two-prompt pipeline) to decide whether to auto-apply or just store it for
   later review.
3. Tailor a LaTeX resume (and cover letter, when required) per job using
   tiered OpenRouter models, render to PDF, and store artifacts in S3.
4. Automatically fill and submit job applications on the web where technically
   and legally reasonable, tracking every action taken.

**Current state (confirmed from codebase inspection):**
- The repo is currently a single Laravel project at the repository root (no
  `backend/`/`frontend/` split yet); everything is Blade views under a
  session-based `EnsureTokenIsValid` middleware (`routes/web.php`,
  `resources/views`). No React or any frontend framework exists yet.
- No real API surface exists beyond the Sanctum stub in `routes/api.php`.
- Jobs are fetched from Adzuna only (`JobListingController::index`); a
  RapidAPI LinkedIn integration and a Panther/ChromeDriver scraper exist but
  are unused/dead code (`JobsController`, commented blocks in
  `JobListingController`).
- The only LLM integration today is a local Ollama sidecar
  (`ollama.py` + `Http::post('http://localhost:5001/llm')`) — no OpenRouter,
  no cloud LLM, no LaTeX generation anywhere in the repo.
- `applications` and `tailored_resumes` tables exist in migrations but have
  no working model/controller logic (`Application` controller is an empty
  stub).
- `2025_03_11_083440_create_user_profiles_table.php` manually bundles five
  unrelated tables (`user_profiles`, `resume_uploads`, `job_listings`,
  `tailored_resumes`, `applications`) into a single migration file instead of
  following Laravel's one-table-per-migration convention. Requirement 1C
  (below) governs how this is handled going forward.
- S3 is configured in `config/filesystems.php` but unused (no AWS Flysystem
  package installed, empty AWS credentials); local `public` disk is used
  instead.
- Panther is a `require-dev`-only dependency, meaning it isn't installed in
  production today.

**Already completed (Task 1 of tasks.md):** the `Application::$table`
visibility bug is fixed, `Resume::$casts` now correctly casts `parsed_data`/
`job_analysis` to `array`, `resume_uploads`/`ResumeUpload` have been
consolidated into `resumes` via proper migrations, the legacy `Jobs` model/
`JobsController` are retired, and `AnalyzeResumeJob` consistently typehints
the consolidated `Resume` model. These items are kept in this document for
historical context but are no longer open work.

**Important constraint to design around:** LinkedIn's User Agreement
(Section 8.2) explicitly prohibits bots and automated access, and companies
built around programmatic LinkedIn access (e.g. Proxycurl) have been sued and
shut down for it. This is a civil ToS matter, not a criminal one, but using an
automated agent to browse/apply on LinkedIn with a personal account still
risks that account being restricted or banned. The design must:
- Prefer ToS-friendly sources for job **discovery** (job aggregator APIs,
  direct company ATS boards like Greenhouse/Lever/Workday, and LLM web
  search) over scraping LinkedIn's search UI.
- Treat LinkedIn "Easy Apply" automation as an explicit, opt-in, clearly
  risk-labeled adapter — off by default — while ATS-direct application
  adapters are the primary, lower-risk auto-apply path.

---

## Requirement 1: Decoupled Backend + Frontend Architecture

**User Story:** As a developer, I want the Laravel API and the React
frontend to live in separate top-level folders as independent projects, so
each can be developed, dependency-managed, built, and deployed on its own
timeline without the other's tooling getting in the way.

### Acceptance Criteria

1. WHEN the repository is restructured THEN the system SHALL place the
   existing Laravel application (routes, app/, config/, database/, artisan,
   composer.json, etc.) into a `backend/` folder, and SHALL scaffold a
   standalone React application (its own `package.json`, its own bundler
   config, e.g. Vite-for-React or Create React App/Next.js as decided) into a
   `frontend/` folder at the repository root, sitting alongside `backend/`.
2. WHEN the backend runs THEN it SHALL expose `/api/*` JSON routes only for
   all authenticated application functionality (no Blade views rendering the
   dashboard, resumes, jobs, or profile screens); the backend SHALL NOT build,
   bundle, or serve the frontend's static assets.
3. WHEN the frontend runs THEN it SHALL be started via its own dev server
   (its own `npm run dev`/equivalent, on its own port) and built via its own
   build command, independent of `artisan serve`/Laravel's Vite integration;
   the frontend SHALL communicate with the backend exclusively via HTTP calls
   to the backend's base API URL (configured via a frontend-side env variable,
   e.g. `VITE_API_BASE_URL`), never via shared PHP session state or shared
   filesystem access.
4. WHEN client-side routing is implemented in the frontend THEN it SHALL
   cover: login/register, dashboard, resume upload/list, job listings, job
   detail, application tracker, and profile/settings — the same functional
   coverage as Requirement 1's prior SPA-in-Laravel approach, just served from
   the independent `frontend/` project.
5. WHEN authentication is designed across the two projects THEN the system
   SHALL use a token-based approach suited to a cross-origin frontend (Laravel
   Sanctum API tokens issued on login, sent by the frontend as a
   `Bearer` header on every request) rather than same-origin SPA cookie
   authentication, since the frontend is no longer served from the backend's
   origin. (See Requirement 1B for the full auth/CORS design.)
6. WHEN an authenticated user's token expires or is invalid THEN the backend
   API SHALL respond with HTTP 401 and the frontend SHALL clear the stored
   token and redirect to login.
7. IF a legacy Blade route (`jobs.index`, `jobs.show`, `resumes.*`,
   `UserProfile.*`, `dashboard.*`) is requested after cutover THEN the system
   SHALL return 404 or a plain redirect notice — the backend SHALL NOT retain
   dual-maintained Blade view logic once the frontend equivalent is verified.
8. WHEN `Application::$table` and other legacy bugs are encountered THEN the
   system SHALL fix them as part of the migration (already completed for the
   items listed under Task 1; any newly discovered ones follow the same rule
   going forward).
9. WHEN CORS is configured on the backend THEN the system SHALL restrict
   allowed origins to the frontend's known dev and production URL(s) only
   (`config/cors.php` `allowed_origins`), and SHALL NOT use a wildcard (`*`)
   origin given that requests will carry an `Authorization` bearer token.

## Requirement 1B: Backend/Frontend Project Boundaries & Local Development

**User Story:** As a developer, I want clear, independent setup and run
instructions for the backend and frontend, so either can be worked on without
needing the other running, except when testing integrated behavior.

### Acceptance Criteria

1. WHEN a developer sets up the project THEN the repository SHALL provide
   separate setup instructions for `backend/` (composer install, `.env`,
   `php artisan migrate`, `php artisan serve` or Sail) and `frontend/` (npm/
   pnpm install, `.env`/`.env.local` with `VITE_API_BASE_URL`, dev server
   command), documented in each folder's own README (or a root README
   sections for each).
2. WHEN the frontend needs to call the backend locally THEN the system SHALL
   default `VITE_API_BASE_URL` (or equivalent) to the backend's local dev URL
   (e.g. `http://localhost:8000/api`), configurable per environment via env
   files, never hard-coded in frontend source.
3. WHEN either project's dependencies change THEN the system SHALL ensure no
   shared `node_modules`/`vendor` directory exists between them — `backend/`
   SHALL have no `package.json`-driven frontend framework dependencies (React,
   React Router, etc.), and `frontend/` SHALL have no PHP/Composer
   dependencies.
4. WHEN CI or a build pipeline runs THEN the system SHALL be able to build/
   test `backend/` and `frontend/` independently (separate build steps,
   separate test suites: PHPUnit for `backend/`, the frontend's own test
   runner e.g. Vitest for `frontend/`).
5. WHEN the two projects are deployed THEN the system SHALL support deploying
   them to different hosts/services if desired (e.g. backend on a PHP host,
   frontend on a static host/CDN), since neither depends on being colocated
   at deploy time — the only runtime coupling is the frontend's configured
   API base URL and the backend's configured CORS allow-list.

## Requirement 1C: Migration-Based Schema Management

**User Story:** As a developer, I want every database table created and
changed exclusively through individual Laravel migrations, so schema history
is trackable, reviewable, and safely reversible instead of relying on
manually-run SQL or ad hoc multi-table migration files.

### Acceptance Criteria

1. WHEN any new table is needed for this feature (e.g. `job_scores`,
   `tailored_documents`, `model_usage_logs`, `provider_usage`,
   `user_automation_settings`) THEN the system SHALL create it via a
   dedicated migration file generated with `artisan make:migration
   create_{table}_table`, one table per migration file, following Laravel's
   standard convention — not by hand-editing the database or adding tables to
   an unrelated existing migration.
2. WHEN an existing table needs new columns or constraints (e.g. `resumes`
   gaining `storage_disk`/`status`, `job_listings` gaining `pipeline_stage`,
   `applications` gaining `automation_log`) THEN the system SHALL add a
   separate `artisan make:migration add_{columns}_to_{table}_table` file
   rather than editing a historical `create_*` migration in place.
3. WHEN the legacy multi-table migration
   (`2025_03_11_083440_create_user_profiles_table.php`, which currently
   creates `user_profiles`, `resume_uploads`, `job_listings`,
   `tailored_resumes`, and `applications` together) is touched as part of this
   work THEN the system SHALL leave already-applied history intact (no
   editing of a migration that has already run in any environment) and SHALL
   split any further schema changes to those tables into their own new
   migration files going forward.
4. WHEN `resume_uploads` is dropped and its data folded into `resumes`
   (Requirement 1.5) THEN the system SHALL perform the drop and any data
   backfill through a migration (`up()`/`down()` with reversible logic, or an
   explicit note in `down()` if the drop is intentionally irreversible) rather
   than a manual `DROP TABLE` or ad hoc SQL script.
5. WHEN `admin_resume.sql` (a raw phpMyAdmin dump currently checked into the
   repo root) is referenced for schema/seed setup THEN the system SHALL NOT
   depend on running that file for setup going forward; equivalent seed data
   needed for local development SHALL be expressed as Laravel database
   seeders (`database/seeders`) instead.
6. WHEN a developer runs `php artisan migrate` on a fresh database THEN the
   system SHALL produce the complete, correct schema for every table this
   feature introduces or modifies, with no manual SQL step required.
7. WHEN Eloquent models are created for new tables THEN the system SHALL
   generate them via `artisan make:model` (optionally with `-m` to pair a
   model with its migration) so model/migration pairs are created
   consistently, rather than hand-writing model files with fillable/casts
   that don't have a matching migration.

## Requirement 2: Unified Resume & Profile Ingestion

**User Story:** As a job seeker, I want to upload my resume once and have my
profile (skills, experience, education, links) extracted automatically, so
downstream tailoring and scoring have accurate source data.

### Acceptance Criteria

1. WHEN a user uploads a resume (PDF) THEN the system SHALL store the
   original file in S3 (not local disk), extract text via `pdftotext`, and
   create a single `resumes` record (replacing the separate `Resume` /
   `ResumeUpload` tables).
2. WHEN a resume is uploaded THEN the system SHALL enqueue a queued job that
   calls an LLM (via the new OpenRouter integration, replacing the local
   Ollama sidecar) to extract structured profile data: skills, experience,
   education, keywords, summary, suggested job roles, location, and social
   links.
3. WHEN structured extraction completes THEN the system SHALL update
   `UserProfile` via `updateOrCreate`, with `parsed_data`/`job_analysis`-style
   JSON columns properly cast to `array` on the model (fixing the current
   missing casts on `Resume`).
4. IF resume parsing or LLM extraction fails THEN the system SHALL mark the
   resume record with a failure status and surface the error to the user in
   the React UI, without leaving the record stuck in a silent "processing"
   state.
5. WHEN a user views their profile in the frontend THEN the system SHALL allow
   manual edits to any extracted field (skills, location, links, experience,
   education) since automatic extraction may be imperfect.

## Requirement 3: Multi-Source Job Discovery

**User Story:** As a job seeker, I want jobs pulled from multiple sources
automatically based on my profile, so I have a wider and more reliable pool of
relevant openings than a single API provides.

### Acceptance Criteria

1. WHEN the job discovery pipeline runs (scheduled and on-demand) THEN the
   system SHALL query job data from at least these source types, each
   implemented as an independent `JobSourceProvider`:
   - Aggregator job-search APIs (e.g. Adzuna — already integrated; JSearch or
     similar RapidAPI aggregator as a second source) for broad coverage.
   - Direct ATS job boards (Greenhouse, Lever, Workday public job-board JSON
     endpoints) for companies the user is targeting, since these are designed
     for programmatic consumption and carry materially lower ToS risk than
     scraping LinkedIn's UI.
   - An LLM web-search provider (OpenRouter models with web-search/plugin
     capability, e.g. `:online` search-augmented models, or a dedicated search
     API such as Exa/Tavily/Bing if the chosen OpenRouter models don't support
     it directly) that takes the user's suggested roles + location and returns
     candidate job postings with source URLs.
2. WHEN a new `JobSourceProvider` is added THEN the system SHALL NOT require
   changes to the scoring, tailoring, or apply pipelines — providers SHALL
   normalize results into the shared `job_listings` schema.
3. WHEN a job is fetched from any source THEN the system SHALL de-duplicate
   against existing `job_listings` rows using a composite key (normalized
   title + company + location, or source-provided external ID when present)
   rather than relying solely on `api_id`, since different sources will
   surface the same posting.
4. WHEN a job's full description is missing or truncated (a known gap with
   aggregator APIs that return partial text) THEN the system SHALL enqueue a
   background enrichment job to fetch the full description from the
   `application_url`, preferring: (a) direct HTTP fetch + HTML parsing if the
   page doesn't require JS, (b) headless-browser rendering (replacing the
   current ad hoc Panther code with one consolidated, queued implementation)
   only when necessary, and (c) skip enrichment entirely for domains disallowed
   by `robots.txt`.
5. WHEN direct scraping of LinkedIn's job-search results is being considered
   as a discovery source THEN the system SHALL NOT implement it — LinkedIn
   search/listing scraping is explicitly out of scope given ToS risk; LinkedIn
   postings SHALL only be reached indirectly (e.g. via aggregator APIs that
   re-index LinkedIn postings, or via a job's own `application_url` if a user
   pastes one manually).
6. WHEN job discovery runs for a user THEN the system SHALL respect
   configurable per-source rate limits and a daily fetch budget to avoid
   API-cost overruns and provider throttling/bans.

## Requirement 4: OpenRouter Tiered Model Routing

**User Story:** As the platform operator, I want every LLM call (scoring,
tailoring, cover letters) routed to a cost-appropriate model, so simple tasks
use cheap models and complex/high-value tasks use stronger models.

### Acceptance Criteria

1. WHEN any pipeline stage needs an LLM call THEN the system SHALL route the
   request through a single `ModelRouterService` backed by the OpenRouter API
   (`config('services.openrouter')`, new `OPENROUTER_API_KEY` env var), never
   calling model providers directly from feature code.
2. WHEN selecting a model tier THEN the system SHALL use a configurable
   ordered list of tiers (e.g. `cheap`, `standard`, `premium`) mapped to
   specific OpenRouter model IDs in config (not hard-coded in code), so model
   choices can be updated as OpenRouter pricing/availability changes.
3. WHEN a job posting includes a parsed salary range THEN the system SHALL
   escalate to a higher tier as salary increases past configurable thresholds
   (e.g. above a top threshold → `premium`), reflecting that higher-value
   applications justify better tailoring.
4. WHEN a job posting has no salary data THEN the system SHALL select a tier
   based on task complexity heuristics (e.g. JD length/token count, number of
   requirements/qualifications detected, resume-to-JD skill-gap size) rather
   than defaulting silently to the most expensive tier.
5. WHEN an OpenRouter call fails or times out THEN the system SHALL retry on
   a fallback model within the same tier (OpenRouter's model list supports
   multiple candidates) before failing the job, and SHALL log the failure with
   enough detail (model id, tier, task type, error) to diagnose it.
6. WHEN any OpenRouter call completes THEN the system SHALL record usage
   (model id, tier, input/output token counts, cost estimate, task type,
   related job/resume id) in a `model_usage_logs` table for cost tracking.
7. WHEN configuring OpenRouter THEN the system SHALL never commit the API key
   to source control; it SHALL be read from `.env`/`config/services.php` only.

## Requirement 5: Job Description Scoring Pipeline (Two-Prompt)

**User Story:** As a job seeker, I want each job description automatically
scored against my profile, so the system only auto-applies to strong matches
and simply stores the rest for me to review later.

### Acceptance Criteria

1. WHEN a job listing's description is available (post-enrichment) THEN the
   system SHALL run a two-prompt scoring pipeline via the `ModelRouterService`:
   - **Prompt 1 (Fit analysis):** given the job description and the user's
     profile/resume, extract required skills, seniority, and requirements, and
     produce a structured skill/requirement-gap analysis.
   - **Prompt 2 (Rating decision):** given the Prompt 1 analysis, produce a
     star rating (1–5) plus a short rationale and a recommended action
     (`auto_apply` or `store_only`).
2. WHEN the rating is 4 or 5 stars THEN the system SHALL mark the job
   `recommended_action = auto_apply` and enqueue it into the tailoring
   pipeline automatically (subject to the user's global auto-apply toggle
   described in Requirement 8).
3. WHEN the rating is below the configurable auto-apply threshold (default:
   below 4 stars) THEN the system SHALL mark the job `recommended_action =
   store_only`, leaving it visible in the frontend for manual review/apply, and
   SHALL NOT proceed automatically into tailoring or auto-apply.
4. WHEN scoring completes THEN the system SHALL persist the rating, rationale,
   gap analysis, and both prompts' raw model output in a `job_scores` table
   linked to the job listing and user, so results are auditable and don't need
   to be recomputed.
5. IF either prompt in the scoring pipeline fails to return parseable
   structured output THEN the system SHALL retry once with a stricter
   formatting instruction, then fall back to `store_only` and flag the job for
   manual review rather than silently dropping it.
6. WHEN a user manually re-triggers scoring for a job (e.g. after editing
   their profile) THEN the system SHALL re-run both prompts and version the
   new score alongside (not overwrite) the previous one.

## Requirement 6: LaTeX Resume Tailoring

**User Story:** As a job seeker, I want my resume tailored to each job
description and rendered as a polished PDF, so my application matches the
role without me manually rewriting it every time.

### Acceptance Criteria

1. WHEN a job enters the tailoring pipeline (via auto-apply routing or a
   manual "tailor this" action in the frontend) THEN the system SHALL generate a
   tailored resume by: (a) sending the base resume content + job description
   + gap analysis to the `ModelRouterService`, (b) receiving structured,
   tailored resume content (summary, reordered/reworded bullet points,
   emphasized skills) as JSON, and (c) injecting that content into a LaTeX
   resume template.
2. WHEN LaTeX content is generated THEN the system SHALL compile it to PDF
   using a queued job (not a synchronous request) via a LaTeX engine available
   in the deployment environment (e.g. `tectonic` or `pdflatex`/`xelatex`
   installed in the container/host), with compilation errors captured and
   logged rather than crashing the queue worker.
3. WHEN the user has multiple resume templates THEN the system SHALL allow
   selecting a template per tailoring run, storing the template identifier
   used alongside the output.
4. IF LaTeX compilation fails (e.g. malformed content from the LLM) THEN the
   system SHALL retry the LLM generation step with a corrective prompt
   (max N retries, configurable), and if still failing, SHALL mark the
   tailoring run `failed` and notify the user rather than silently discarding
   the job.
5. WHEN a tailored resume PDF is produced successfully THEN the system SHALL
   store it as specified in Requirement 7 and link it to both the
   `job_listings` row and the `applications` row it will be used for.
6. WHEN tailoring a resume THEN the system SHALL preserve factual accuracy —
   the prompt SHALL explicitly instruct the model not to fabricate employers,
   titles, dates, or skills not present in the source resume/profile, and the
   system SHOULD run a lightweight post-check (e.g. diffing company/title/date
   tokens) flagging suspicious fabrications for user review before use in an
   auto-applied application.

## Requirement 7: Cover Letter Tailoring

**User Story:** As a job seeker, I want a tailored cover letter generated
automatically only when a job posting requires one, so I don't waste tailoring
effort or model cost on postings that don't need it.

### Acceptance Criteria

1. WHEN the job description or application form indicates a cover letter is
   required or optional-but-requested THEN the system SHALL generate a
   tailored cover letter using the same `ModelRouterService` tiering logic as
   resumes, rendered from a LaTeX cover-letter template.
2. WHEN a job posting does not mention or require a cover letter THEN the
   system SHALL skip cover letter generation entirely for that application to
   avoid unnecessary LLM cost.
3. WHEN a cover letter PDF is produced THEN the system SHALL store it in S3
   (Requirement 7... see Requirement 7 storage rules below) and link it to the
   same `applications` row as the tailored resume.
4. IF cover letter compilation fails THEN the system SHALL apply the same
   retry/fallback/notify behavior defined for resume compilation
   (Requirement 6.4).

## Requirement 8: S3 Artifact Storage

**User Story:** As the platform operator, I want all generated documents
stored durably in S3 with clear organization, so files survive server
redeploys and can be retrieved reliably by the auto-apply step and the user.

### Acceptance Criteria

1. WHEN any resume (original upload), tailored resume PDF, or cover letter PDF
   is finalized THEN the system SHALL store it in S3 using the `s3` disk
   (adding `league/flysystem-aws-s3-v3` to `backend/composer.json`, populating
   `AWS_*` env vars) rather than the local `public` disk.
2. WHEN storing an object THEN the system SHALL use a deterministic, namespaced
   key structure (e.g. `users/{user_id}/resumes/original/{resume_id}.pdf`,
   `users/{user_id}/applications/{application_id}/resume.pdf`,
   `users/{user_id}/applications/{application_id}/cover-letter.pdf`) so files
   can be located without a database lookup if needed.
3. WHEN a file is stored THEN the system SHALL persist its S3 path (or a
   signed-URL-generating reference) on the owning record (`resumes`,
   `applications`) rather than a public permanent URL, and SHALL generate
   temporary signed URLs on demand for the frontend to display/download.
4. WHEN an application or resume record is deleted THEN the system SHALL also
   remove (or explicitly schedule removal of) its associated S3 objects, to
   avoid orphaned storage costs.
5. IF S3 upload fails THEN the system SHALL retry with backoff (queued job
   retry) and SHALL NOT mark the tailoring/application step as complete until
   storage succeeds.

## Requirement 9: Automated Job Application ("Auto-Apply")

**User Story:** As a job seeker, I want the system to automatically fill and
submit applications for jobs it scored highly, so I spend less time on
repetitive application forms.

### Acceptance Criteria

1. WHEN a job is routed to auto-apply (Requirement 5.2) and the user's global
   auto-apply toggle is enabled THEN the system SHALL attempt to submit the
   application using an `ApplyAdapter` selected by the job's `application_url`
   domain/platform.
2. WHEN the target platform is a direct ATS with a known form structure
   (Greenhouse, Lever, Workday, etc.) THEN the system SHALL use a dedicated
   adapter that fills the form fields (name, email, phone, resume upload,
   cover letter upload, standard screening questions) using profile data and
   the freshly tailored documents.
3. WHEN the target platform is LinkedIn "Easy Apply" THEN the system SHALL
   only attempt automated submission if the user has explicitly opted in to a
   separate "LinkedIn auto-apply (higher risk)" setting, distinct from the
   general auto-apply toggle, with in-app copy explaining that this may violate
   LinkedIn's Terms of Service and carries account-restriction risk. This
   adapter SHALL be rate-limited more conservatively than ATS adapters.
4. WHEN an application form includes questions the system cannot answer
   confidently (e.g. free-text questions requiring personal judgment, legal
   attestations, salary expectations without user-provided data) THEN the
   system SHALL pause that application and flag it for manual completion in
   the frontend rather than guessing or submitting incomplete answers.
5. WHEN an automated submission completes (success or failure) THEN the system
   SHALL record an `applications` row (or update the existing one) with
   `status` (`applied`, `failed`, `needs_review`), a timestamp, the adapter
   used, and any error/screenshot evidence captured during the attempt.
6. WHEN automation encounters a CAPTCHA, login wall, or bot-detection page
   THEN the system SHALL abort that attempt, mark it `needs_review`, and
   SHALL NOT attempt to bypass anti-bot protections.
7. WHEN running auto-apply automation THEN the system SHALL enforce a
   configurable per-day and per-platform application cap to reduce the risk
   of triggering rate-limit or anti-abuse detection on any target site.
8. WHEN a `store_only` job (Requirement 5.3) is later chosen by the user for
   manual apply THEN the system SHALL allow triggering the same tailoring +
   apply pipeline on demand from the frontend.

## Requirement 10: Application Tracking Dashboard

**User Story:** As a job seeker, I want a single view of every job discovered,
scored, tailored, and applied to, so I can track my pipeline and intervene
where needed.

### Acceptance Criteria

1. WHEN the user opens the applications view in the frontend THEN the system SHALL
   list all `job_listings` with their current pipeline stage (`discovered`,
   `scored`, `tailoring`, `tailored`, `applying`, `applied`, `failed`,
   `needs_review`, `store_only`) and star rating.
2. WHEN the user selects a job THEN the system SHALL show the full JD, score
   rationale, tailored resume/cover letter (with signed S3 download links),
   and the automation log for that application (what the adapter did, and
   any errors/screenshots).
3. WHEN a job is in `needs_review` THEN the system SHALL surface it with a
   clear call-to-action (e.g. "answer these questions to continue" or
   "review possible resume fabrication") in the frontend.
4. WHEN the user updates an application's status manually (e.g. marking an
   interview received, rejection) THEN the system SHALL persist that update
   distinctly from the automation-driven status so manual and automated
   status changes aren't confused.

## Requirement 11: Pipeline Orchestration & Background Processing

**User Story:** As the platform operator, I want every long-running step
(discovery, enrichment, scoring, tailoring, applying) to run as a queued,
retryable background job, so the web/API layer stays fast and failures don't
require manual restarts.

### Acceptance Criteria

1. WHEN any pipeline stage is triggered (scheduled discovery, enrichment,
   scoring, tailoring, apply automation) THEN the system SHALL dispatch a
   Laravel queued job (`ShouldQueue`) rather than executing synchronously in a
   controller, replacing the current pattern where `JobListingController::show()`
   scrapes synchronously on page view.
2. WHEN a pipeline job fails THEN the system SHALL use Laravel's built-in retry
   /backoff configuration and land permanently-failed jobs in `failed_jobs`
   for inspection, with a linked pipeline-stage status update visible in the
   frontend (not just a silent log entry).
3. WHEN one pipeline stage completes successfully THEN the system SHALL
   dispatch the next stage's job (discovery → enrichment → scoring →
   [tailoring → apply] or [store_only]) via job chaining, so the whole flow
   from "job discovered" to "application submitted" requires no manual
   intervention for auto-apply candidates.
4. WHEN running in production THEN the system SHALL document and configure a
   persistent queue worker process (e.g. Supervisor-managed `queue:work`),
   since none exists today beyond manual `php artisan queue:work` execution.

## Requirement 12: Migration & Cutover Strategy

**User Story:** As the developer, I want a clear, incremental migration path
from the current Blade monolith to the new decoupled backend/frontend
architecture, so the existing working features (auth, resume upload,
Adzuna-based job listing) keep working throughout the rewrite.

### Acceptance Criteria

1. WHEN the migration begins THEN the system SHALL introduce Laravel API
   routes/controllers within the current project alongside the existing Blade
   routes (strangler pattern) BEFORE the `backend`/`frontend` folder split
   happens, so the app keeps working at every step; the folder-split
   (Requirement 1.1) and the frontend scaffold can then proceed against a
   backend that already exposes working `/api/*` endpoints. The system SHALL
   NOT delete working Blade functionality before its API/frontend replacement
   is verified.
2. WHEN each frontend feature area reaches parity with its Blade equivalent
   (auth, dashboard, resumes, jobs, profile) THEN the system SHALL switch the
   default route for that area to the frontend and remove the corresponding
   Blade controller methods/views in the same change, avoiding long-lived
   duplicate implementations.
3. WHEN legacy/dead code is identified (the local Ollama `ollama.py` sidecar,
   any remaining commented-out RapidAPI/LinkedIn blocks) THEN the system
   SHALL remove it as part of the corresponding migration step rather than
   leaving it alongside the new implementation. (`JobsController`, the unused
   `Jobs` model, and the `Resume`/`ResumeUpload` duplication were already
   removed in Task 1.)
4. WHEN the migration is complete THEN the system SHALL have zero Blade view
   files driving authenticated application functionality (auth screens may
   remain server-rendered if simpler, but that decision SHALL be explicit, not
   incidental).

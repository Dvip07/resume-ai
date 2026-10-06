# Backend — Laravel JSON API

The Laravel application that powers resume-ai. It exposes a JSON API under
`/api/*` and is developed, tested, and deployed independently of
[`../frontend`](../frontend) (the standalone React app — see the root
[README](../README.md) for its status).

- PHP 8.2+, Laravel 11
- Auth: Laravel Sanctum personal access tokens, delivered to browsers in an
  httpOnly cookie and also accepted as `Authorization: Bearer <token>`
  (see [Authentication](#authentication))
- Queue: database driver by default
- Tests: PHPUnit

## Requirements

- PHP 8.2 or newer with the usual Laravel extensions (`mbstring`, `openssl`,
  `pdo`, `tokenizer`, `xml`, `curl`, `fileinfo`)
- Composer 2
- MySQL 8 (or SQLite for a zero-setup local database)
- `pdftotext` (poppler-utils) for resume text extraction
- ChromeDriver, only if you exercise the job-description crawler
  (`brew install chromedriver`, or drop the binary at `backend/drivers/chromedriver`)

## Setup

All commands below run from this `backend/` directory.

### 1. Install PHP dependencies

```bash
composer install
```

### 2. Create and configure `.env`

```bash
cp .env.example .env
php artisan key:generate
```

Then set the values you need:

| Variable | Purpose |
| --- | --- |
| `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Database connection. `.env.example` ships with `DB_CONNECTION=sqlite`; switch to `mysql` and fill in the rest for a MySQL setup. |
| `APP_URL` | Backend base URL, e.g. `http://localhost:8000`. |
| `FRONTEND_URL` | Origin the frontend dev server runs on (default `http://localhost:5173`). Read by `config/cors.php` for the CORS allow-list — no wildcard origins. |
| `AUTH_COOKIE_SAME_SITE`, `AUTH_COOKIE_SECURE` | Attributes of the httpOnly auth cookie. `lax`/`false` locally; a cross-site production frontend needs `none`/`true`. |
| `AUTH_COOKIE_TTL`, `AUTH_COOKIE_PATH`, `AUTH_COOKIE_DOMAIN` | Auth cookie lifetime (minutes) and scope. |
| `AUTH_COOKIE_REQUIRE_XRW` | Keep `true`. Requires `X-Requested-With: XMLHttpRequest` before the auth cookie is honoured (CSRF mitigation). |
| `ADZUNA_APP_ID`, `ADZUNA_APP_KEY` | Job discovery via the Adzuna API. |
| `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET` | S3 document storage. `AWS_URL`/`AWS_ENDPOINT`/`AWS_USE_PATH_STYLE_ENDPOINT` are optional and only needed for an S3-compatible endpoint (MinIO, LocalStack, R2). |
| `RESUME_STORAGE_DISK` | Disk for resume/artifact storage (`config/filesystems.php` → `resume_disk`). Defaults to `s3`; set to `public` for local dev without AWS credentials. |

Never commit `.env` or real credentials.

Resume uploads are written to the `RESUME_STORAGE_DISK` disk under a
deterministic key: `users/{user_id}/resumes/original/{resume_id}.pdf`. The
`resumes` row records both the key (`file_path`) and the disk
(`storage_disk`) — never a public URL; download links are signed on demand.

If you use SQLite, create the database file first:

```bash
touch database/database.sqlite
```

### 3. Run migrations

```bash
php artisan migrate
```

Optional local seed data (replaces the old `admin_resume.sql` dump):

```bash
php artisan db:seed
```

### 4. Link storage (for locally stored uploads)

```bash
php artisan storage:link
```

### 5. Start the dev server

```bash
php artisan serve
```

The API is then reachable at `http://localhost:8000/api`. Point the frontend's
`VITE_API_BASE_URL` at that value.

### 6. Run the queue worker

Resume parsing, job description enrichment, and document generation run on the
queue, so keep a worker running while developing those flows:

```bash
php artisan queue:work
```

That bare command is fine for development only. In production the worker is
Supervisor-managed with explicit timeout, restart and recycling behaviour —
see [Queue workers in production](../docs/deployment/queue-workers.md), and
the committed program config at
[`deploy/supervisor/resume-ai-worker.conf`](deploy/supervisor/resume-ai-worker.conf).
The short version:

- Workers run under Supervisor (not Horizon — the queue driver is `database`
  and Horizon needs Redis).
- `php artisan queue:restart` after every deploy, or workers keep serving the
  old code.
- Watch `php artisan queue:failed`; a growing `failed_jobs` table is the
  signal that the pipeline is dying rather than idling.
- The Playwright `automation-worker` is a separate process with its own
  lifecycle. Restarting queue workers does not restart it.

## Tests

```bash
php artisan test
```

Or via PHPUnit directly:

```bash
./vendor/bin/phpunit
```

## Docker (optional)

A Compose setup at the repository root builds this app plus MySQL:

```bash
cd .. && docker compose up --build
```

The backend is published on `http://localhost:8001` and MySQL on port `3308`.

## PDF rendering (LaTeX)

Generated resumes are compiled to PDF by a LaTeX engine. Settings live in
`config/latex.php`.

**Default: tectonic.** The Docker image at `docker/php/Dockerfile` installs a
pinned `tectonic` binary to `/usr/local/bin`, so nothing extra is needed in a
container. tectonic is a single self-contained binary rather than a full TeX
Live install, which is what keeps the image small. It fetches its TeX bundle
over the network on first run and caches it under `/root/.cache/Tectonic`
(override with `TECTONIC_CACHE_DIR`), so the first compile after a rebuild is
slower; a persistent volume on that path avoids re-downloading it.

Verify the engine is installed and runnable in the current environment:

```bash
php artisan latex:check
```

It exits `0` and reports the resolved binary path when the engine is available,
and exits `1` with the env vars to fix when it isn't.

**Fallback: pdflatex.** Where tectonic can't be installed — a host with an
existing TeX Live, or a platform with no tectonic build — install the LaTeX
packages and switch engines:

```bash
sudo apt-get install -y texlive-latex-recommended texlive-fonts-recommended
```

```dotenv
LATEX_ENGINE=pdflatex
```

If the engine already exists somewhere non-standard, leave `LATEX_ENGINE` alone
and point `LATEX_BINARY` at the absolute path instead — it wins over the PATH
lookup, but only when it names a real executable.

Two things worth knowing when this runs on the queue:

- `LATEX_TIMEOUT` (default `120` seconds) caps a single compile. A LaTeX run
  that hasn't finished by then is usually stuck waiting on stdin after a
  document error, so the cap turns a hung worker into a failed render.
- A queue worker's `PATH` is often not the `PATH` of your interactive shell
  (supervisor, systemd, and cron all set their own). `latex:check` passing in a
  terminal doesn't guarantee the worker can find the binary — run it the same
  way the worker runs, or set `LATEX_BINARY` to sidestep PATH entirely.

## API surface

Routes live in `routes/api.php`. Current groups:

- `/api/auth/register`, `/api/auth/login` (issues a token), `/api/auth/logout`,
  and `/api/me` for the current user
- `/api/resumes` — upload, list, show, delete
- `/api/profile` — read (`GET`) and update (`PATCH`) the extracted profile
- `/api/jobs` — list and show discovered jobs
- `/api/applications` — read application/pipeline state

Every route outside `auth/register` and `auth/login` requires an
`Authorization: Bearer <token>` header and returns `401` without a valid token.

## Notes

- This project has no frontend-framework dependencies. React and its tooling
  live entirely in `frontend/`.
- Deeper reference docs (crawler, services, deployment) are in
  [`../docs`](../docs).

## Authentication

Open decision #6 in the spec resolved to a **backend-set httpOnly cookie**, so
the token is never exposed to frontend JavaScript.

How it works:

1. `POST /api/auth/login` and `POST /api/auth/register` issue a Sanctum
   personal access token. It comes back in the JSON body (for non-browser API
   clients) **and** in an httpOnly cookie named `auth_token`
   (`App\Support\AuthTokenCookie`, configured in `config/auth_cookie.php`).
2. `App\Http\Middleware\AttachAuthTokenFromCookie` runs at the front of the
   `api` middleware group. When a request has no `Authorization` header, it
   copies the cookie value into `Authorization: Bearer <token>`, so
   `auth:sanctum` is unchanged and an explicit header always wins.
3. `POST /api/auth/logout` revokes the current token and returns an expired
   cookie so the browser drops it.

Cookie encryption: the cookie is intentionally **plaintext**. `api/*` routes run
the `api` group, which does not include `EncryptCookies`, so nothing would
decrypt it inbound; the name is also in `encryptCookies(except: ...)` in
`bootstrap/app.php` so the value stays consistent even if the `web` group ever
sees it. The value is a Sanctum token (already an opaque high-entropy secret).

CSRF: because the credential is now sent automatically by the browser, the
cookie is only honoured when the request also carries
`X-Requested-With: XMLHttpRequest` (`AUTH_COOKIE_REQUIRE_XRW=true`). A
cross-site HTML form post cannot set a custom header, and a fetch/XHR attempt
triggers a CORS preflight that `config/cors.php`'s explicit origin allow-list
rejects. Requests authenticating with an explicit `Authorization` header skip
this check, since that header is never sent automatically.

CORS: `supports_credentials` is `true` (required for the cookie to travel
cross-origin) with an explicit `allowed_origins` list — never a wildcard, which
browsers reject alongside credentials anyway.

### CORS verification

Verified against a running `php artisan serve` with real preflight and actual
requests:

- `Origin: http://localhost:5173` (allowed) — preflight returns `204` with
  `Access-Control-Allow-Origin: http://localhost:5173` and
  `Access-Control-Allow-Credentials: true`; the same headers come back on the
  actual request. The full cookie flow works cross-origin: login sets
  `auth_token` as `httponly; samesite=lax; path=/` with a 14-day `Max-Age` (no
  `Secure` locally, per `AUTH_COOKIE_SECURE`), a follow-up `GET /api/me`
  carrying only that cookie plus `X-Requested-With: XMLHttpRequest` returns
  `200`, the same request without the header returns `401`, and logout returns
  an expired cookie.
- `Origin: http://evil.example.com` (unlisted) — no `Access-Control-Allow-Origin`
  header at all, so the browser blocks the response. A wildcard `*` is never
  returned for any origin.

One implementation detail worth knowing: `fruitcake/php-cors` short-circuits
when exactly **one** origin is configured, emitting that origin unconditionally
rather than comparing it against the request's `Origin`. With the default
`.env` (`FRONTEND_URL=http://localhost:5173`) the list collapses to a single
entry, so an unlisted origin still receives
`Access-Control-Allow-Origin: http://localhost:5173`. The browser still blocks
it — the header doesn't match the requesting origin — and no wildcard is ever
sent, so this is not a bypass. Once `FRONTEND_URL` names a distinct production
origin the list has two entries and the header is omitted outright for unlisted
origins. `tests/Feature/Api/CorsTest.php` covers both shapes.

Cross-site deployment: set `AUTH_COOKIE_SAME_SITE=none` and
`AUTH_COOKIE_SECURE=true` when the frontend is served from a different site
over HTTPS. Locally, Vite (`localhost:5173`) and the API (`localhost:8000`) are
same-site, so `lax` over plain http is fine.

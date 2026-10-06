# Automation worker (Node + Playwright)

Headless-browser sidecar for the job pipeline. It is a separate process from
`backend/` on purpose: PHP stays free of browser-driver dependencies, and the
browser can be scaled, restarted and memory-capped on its own.

This resolves open decision #1 in
`.kiro/specs/job-automation-platform/design.md` in favour of Playwright/Node
over Symfony Panther. Panther is a `require-dev`-only dependency today (so it
isn't installed in production at all), wraps Selenium/ChromeDriver, and has
weaker auto-waiting than Playwright — which matters more for the ApplyAdapter
form automation this worker will also carry (tasks 15.3-15.4).

## What it does today

JD enrichment fallback (Requirement 3.4b). `backend/`'s `JobEnrichmentService`
tries a plain HTTP fetch plus DOM selectors first; only when that yields no
usable content (a client-rendered posting) does it ask this worker to render the
page and hand back the post-JavaScript HTML.

Extraction stays in PHP. The worker returns `outerHTML` and nothing else, so
both enrichment paths are judged by the same selector list, the same length
floor and the same bot-wall markers in `backend/config/enrichment.php`.

## Run it

```bash
cd automation-worker
npm install            # also downloads Chromium via the postinstall hook
cp .env.example .env   # set AUTOMATION_WORKER_TOKEN
npm start
```

Then, in `backend/.env`:

```
AUTOMATION_WORKER_ENABLED=true
AUTOMATION_WORKER_BASE_URL=http://127.0.0.1:8081
AUTOMATION_WORKER_TOKEN=<the same token>
```

Leave `AUTOMATION_WORKER_ENABLED=false` (the default) and the backend never
calls out: enrichment reports `no_content` and the posting is left for a later
retry. Tests and local dev work with no worker running.

## API

`GET /health` → `{ status, browser, warm, inFlight, maxConcurrency }`

`POST /render`, `X-Automation-Token: <token>`:

```json
{
  "url": "https://jobs.example.com/careers/123",
  "waitUntil": "domcontentloaded",
  "waitForSelector": ".job-description",
  "timeoutMs": 30000,
  "settleMs": 1500,
  "userAgent": "ResumeAiBot/1.0 (+job description enrichment)"
}
```

Only `url` is required; everything else falls back to the worker's own config.
Success returns `{ url, finalUrl, status, title, html, elapsedMs }`. Failures
return `{ error, message }` with `401` (bad token), `422` (unusable URL), `503`
(all render slots busy) or `502` (navigation failed). The backend treats every
non-200 as "fallback unavailable" and degrades to the plain-fetch outcome.

`POST /apply`, `X-Automation-Token: <token>`: a declarative step script run in
one fresh browser context (nothing persists between calls, so a whole
application is one request).

```json
{
  "url": "https://jobs.example.com/apply/1",
  "steps": [
    { "kind": "fill", "selector": "#email", "value": "a@b.test" },
    { "kind": "select", "selector": "#country", "value": "CA" },
    { "kind": "check", "selector": "#terms", "checked": true },
    { "kind": "upload", "selector": "#resume", "url": "https://s3…/signed.pdf", "filename": "resume.pdf" },
    { "kind": "waitFor", "selector": "#review", "state": "visible" },
    { "kind": "readText", "selector": ".error", "name": "validation" },
    { "kind": "screenshot", "name": "review page", "fullPage": true },
    { "kind": "click", "selector": "#submit" },
    { "kind": "goto", "url": "https://jobs.example.com/apply/2" }
  ]
}
```

`url` and a non-empty `steps` are required. Optional: `timeoutMs`, `userAgent`,
`viewport` (defaults `1366×900`, min 320, clamped to 3840) and `blockAssets`
(defaults **false** here, unlike `/render`, because screenshots are the audit
trail). Step kinds: `goto`, `fill`, `select` (string or array), `check`,
`click`, `upload` (https signed URL only — never inline bytes), `waitFor`
(`selector`+`state`, or `ms`), `readText`, `screenshot` (`name` required).
Per-step `timeoutMs` is allowed. Ceilings come from config:
`AUTOMATION_WORKER_MAX_APPLY_STEPS`, `…_MAX_APPLY_SCREENSHOTS`,
`…_MAX_UPLOAD_BYTES`.

Success returns:

```json
{
  "url": "…", "finalUrl": "…", "status": 200, "title": "…", "html": "…",
  "elapsedMs": 4210,
  "steps": [{ "index": 0, "kind": "fill", "status": "ok|failed|skipped", "selector": "#email", "error": "…", "text": "…", "name": "…" }],
  "screenshots": [{ "name": "review_page", "format": "png", "bytes": 83120, "base64": "…" }],
  "failedStep": null,
  "navigationFailed": false
}
```

Screenshots come back inline as base64 PNGs, keyed by the step's sanitised
`name`; PHP persists them as the audit trail. The first failing step is
recorded with its selector and message, every later step is `skipped`, and the
artifacts are still returned so PHP can judge the page. Errors are
`{ error, message }` with `401` (bad token), `422` (malformed script or unusable
URL — validation is all-or-nothing, before any browser work), `503` (no slots)
or `502` (initial navigation failed). The worker makes no policy decision:
bot-wall detection, retries and `needs_review` stay in the adapters.

## Apply automation: confirmed scope (task 15.1)

Open decision #1 is confirmed a second time, now for apply automation: the
`ApplyAdapter`s in tasks 15.2-15.9 drive **this** worker. There is no second
browser stack, and Panther is not revived for the apply path — it is still
listed under `require-dev` in `backend/composer.json`, so removing that stale
dependency belongs to the 16.7 dead-code sweep, not here.

### How Laravel invokes it

Unchanged from enrichment: PHP → internal HTTP → worker, authenticated with the
`X-Automation-Token` shared secret, configured once in
`backend/config/services.php` under `automation_worker` (base URL, token,
timeouts). Adapters run inside the queued `SubmitApplication` job and talk to the
worker through a sibling of `App\Services\Enrichment\AutomationWorkerClient`
reusing that same config block — one worker address, one token, two callers.

The division of labour also carries over: **the worker stays a dumb executor.**
Selectors, field mapping, ATS quirks, screening-question answers and the
abort/`needs_review` policy live in PHP adapters, exactly as extraction stayed in
PHP for enrichment. The worker executes steps and reports what happened.

### What adapters can rely on today

From `src/browser.js` / `src/render.js`, already in production use:

- Chromium through Playwright, launched lazily and reused; auto-waiting
  primitives and `waitForSelector`.
- A **fresh browser context per request** — its own cookie jar and storage,
  discarded afterwards. No session bleed between two applications.
- Desktop `1366×900` viewport with JavaScript enabled (several ATS templates
  serve a stripped mobile layout otherwise).
- Per-request navigation budget, caller-overridable but clamped; Laravel's HTTP
  timeout deliberately sits above it so the worker gives the reason.
- Load shedding: `503` past `AUTOMATION_WORKER_MAX_CONCURRENCY` instead of
  queueing, because the caller is a retryable queued job.
- Token auth, loopback-by-default bind, refusal to render private/loopback
  hosts, structured JSON logs.

### What does not exist yet — build it in 15.2

`POST /render` is **single-shot and read-only**: navigate, wait, return
`outerHTML`. It has no form fill, no click, no file upload, no screenshot, and no
way to continue a session across two calls. So 15.2 is not only "define the PHP
interface"; it also adds the worker endpoint the interface compiles down to.

The shape that fits the existing design (one `ApplyResult`, one audit trail) is a
second endpoint — `POST /apply` — taking a **declarative step script** executed
inside one browser context, and returning per-step outcomes plus artifacts:

- steps: `goto`, `fill`, `select`, `check`, `click`, `upload`, `waitFor`,
  `readText`, `screenshot`
- response: per-step status (and which selector failed), captured screenshots,
  final URL, title, and `outerHTML` so PHP can run its own page judgement

Constraints that shape the adapters, worth settling before 15.3 starts:

- **One call per application.** The whole flow has to fit in a single
  request/context lifetime, because nothing persists between calls. A multi-page
  Workday flow is one long step script, not a conversation.
- **Documents must reach the browser.** The request body is capped at 64KB
  today, so inlining a tailored PDF is out. Pass a short-lived S3 signed URL
  (`S3StorageService` already generates them) and let the worker download to a
  temp file for `setInputFiles`; raise the body cap only if a fixture-based test
  harness needs inline bytes.
- **Asset blocking must be off for apply runs.** `AUTOMATION_WORKER_BLOCK_ASSETS`
  drops images, fonts and media — fine for text extraction, wrong when
  screenshots are the audit trail (Req 9.5) and when upload widgets are
  icon-driven. Make it per-request rather than global.
- **Concurrency is shared with enrichment.** The default of 2 covers ~100MB
  contexts; an apply run holds its slot far longer than a render. Either raise
  the ceiling or run a second worker instance for applies.
- **Bot-detection signals stay in PHP.** Adapters reuse
  `enrichment.blocked_title_markers` against the returned title, plus the final
  URL and HTTP status, to detect a CAPTCHA/login wall and abort to
  `needs_review` (Req 9.6). The worker never attempts a bypass, and neither does
  any adapter.

## Security

Internal service, not a public endpoint. It renders whatever URL it is given, so
an exposed instance is an SSRF proxy for the network it sits in.

- Binds to `127.0.0.1` by default. It **refuses to start** on a non-loopback
  address without `AUTOMATION_WORKER_TOKEN` set.
- Rejects non-`http(s)` schemes and literal loopback/private-range hosts
  (override with `AUTOMATION_WORKER_ALLOW_PRIVATE_HOSTS=true` for local fixture
  servers). That check can't catch a public hostname that resolves inward —
  network egress rules are the real control.
- robots.txt is enforced on the PHP side *before* the worker is called, so a
  disallowed URL never reaches a browser.
- Each render gets a fresh browser context, so no cookies or storage carry
  between postings.

## Docker

`docker build -t resume-ai-automation-worker .` — based on
`mcr.microsoft.com/playwright`, which already carries Chromium and its system
libraries. Do not publish the port to the host; keep it on the compose network
and reachable from the backend container only.

## Operating notes

- Memory is `AUTOMATION_WORKER_MAX_CONCURRENCY` × ~100MB plus the browser
  itself. Start at 2.
- Chromium launches lazily on the first render and is then reused, so the first
  request after a restart is ~300ms slower.
- Over concurrency, the worker returns `503` rather than queueing: the caller is
  a retryable queued job, and holding the connection open only risks its
  timeout.

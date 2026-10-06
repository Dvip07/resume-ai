/**
 * Worker configuration, entirely environment-driven so the same image can run
 * locally and in production without a code change.
 *
 * Mirrors the Laravel side: every value here has a counterpart default in
 * `backend/config/services.php` under `automation_worker`.
 */

const int = (value, fallback) => {
  const parsed = Number.parseInt(value ?? '', 10);
  return Number.isFinite(parsed) && parsed > 0 ? parsed : fallback;
};

const bool = (value, fallback) => {
  if (value === undefined || value === '') return fallback;
  return ['1', 'true', 'yes', 'on'].includes(String(value).toLowerCase());
};

export const config = {
  // Loopback by default. This service takes a URL and renders it in a real
  // browser, so it must never be reachable from the public internet: bind it to
  // localhost, or to a private network address behind the container network
  // only, and keep the shared token set.
  host: process.env.AUTOMATION_WORKER_HOST || '127.0.0.1',
  port: int(process.env.AUTOMATION_WORKER_PORT, 8081),

  // Shared secret the caller must send as `X-Automation-Token`. Empty means no
  // auth, which is only tolerable on a loopback bind (the server warns).
  token: process.env.AUTOMATION_WORKER_TOKEN || '',

  // Per-request navigation budget. Laravel's own HTTP timeout is set a little
  // above this so the worker is the one that gives up first and can answer with
  // a reason instead of the call being cut off.
  navigationTimeoutMs: int(process.env.AUTOMATION_WORKER_NAVIGATION_TIMEOUT_MS, 30000),

  // Playwright load state to wait for. `domcontentloaded` plus the settle delay
  // below is enough for the client-rendered postings this exists for, and far
  // more reliable than `networkidle` on pages with analytics beacons or
  // long-polling widgets that never go idle.
  waitUntil: process.env.AUTOMATION_WORKER_WAIT_UNTIL || 'domcontentloaded',

  // Extra quiet period after the load state, to let the framework paint the
  // description container.
  settleMs: int(process.env.AUTOMATION_WORKER_SETTLE_MS, 1500),

  // Response-size ceiling. A job posting is a document; anything larger is a
  // mis-typed URL pointing at an app shell that inlines its own bundle.
  maxHtmlBytes: int(process.env.AUTOMATION_WORKER_MAX_HTML_BYTES, 5000000),

  // Concurrent renders. Each one is a browser context (~50-100MB), so this is
  // the memory knob.
  maxConcurrency: int(process.env.AUTOMATION_WORKER_MAX_CONCURRENCY, 2),

  headless: bool(process.env.AUTOMATION_WORKER_HEADLESS, true),

  // Images/fonts/media are dropped by default: nothing downstream reads pixels,
  // and blocking them is the single biggest win in render time.
  blockAssets: bool(process.env.AUTOMATION_WORKER_BLOCK_ASSETS, true),

  userAgent: process.env.AUTOMATION_WORKER_USER_AGENT || '',

  // Request-body ceiling. `/render` needs a few hundred bytes, but an `/apply`
  // step script carries every answer for a multi-page form, so the old 64KB was
  // too tight. 1MB still rules out inlined document bytes, which is deliberate:
  // uploads arrive as signed URLs, never as base64 in a request log.
  maxBodyBytes: int(process.env.AUTOMATION_WORKER_MAX_BODY_BYTES, 1000000),

  // Apply-run ceilings. Each one exists to stop a buggy adapter from turning one
  // request into an unbounded browser session.
  maxApplySteps: int(process.env.AUTOMATION_WORKER_MAX_APPLY_STEPS, 200),
  maxApplyScreenshots: int(process.env.AUTOMATION_WORKER_MAX_APPLY_SCREENSHOTS, 12),
  maxUploadBytes: int(process.env.AUTOMATION_WORKER_MAX_UPLOAD_BYTES, 10000000),
  maxApplyTextChars: int(process.env.AUTOMATION_WORKER_MAX_APPLY_TEXT_CHARS, 5000),
};

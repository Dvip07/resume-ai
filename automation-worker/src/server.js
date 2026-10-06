import http from 'node:http';
import { config } from './config.js';
import { browserLaunched, closeBrowser } from './browser.js';
import { renderPage } from './render.js';
import { validateApplyRequest, executeApply } from './apply.js';
import { validateTarget } from './urlGuard.js';

/**
 * The automation worker's HTTP surface (design.md, "Why Playwright (Node)
 * instead of Panther (PHP) for browser automation").
 *
 * Two endpoints, no framework — the dependency list is Playwright and nothing
 * else on purpose, because this process runs a browser and every extra package
 * is attack surface next to it.
 *
 *   GET  /health   liveness + whether a browser is already warm
 *   POST /render   { url, ... } -> { html, status, finalUrl, ... }
 *   POST /apply    { url, steps[] } -> per-step outcomes + screenshots + page
 *
 * `/apply` is the executor behind the PHP `ApplyAdapter`s (task 15.2). It runs a
 * declarative step script in one fresh browser context and reports what
 * happened; every decision about what an outcome *means* stays in PHP.
 *
 * This is an INTERNAL service. It renders arbitrary URLs in a real browser, so
 * exposing it publicly would hand anyone an SSRF proxy. Two guards, both on by
 * default: it binds to loopback unless told otherwise, and it requires the
 * `X-Automation-Token` shared secret whenever one is configured. Starting it
 * with no token *and* a non-loopback bind is refused outright rather than
 * warned about.
 */

let inFlight = 0;

const server = http.createServer((req, res) => {
  handle(req, res).catch((error) => {
    log('error', 'Unhandled request failure.', { message: error?.message });
    send(res, 500, { error: 'internal_error', message: 'Unhandled request failure.' });
  });
});

async function handle(req, res) {
  const url = new URL(req.url ?? '/', `http://${req.headers.host ?? 'localhost'}`);

  if (req.method === 'GET' && url.pathname === '/health') {
    return send(res, 200, {
      status: 'ok',
      browser: 'chromium',
      warm: browserLaunched(),
      inFlight,
      maxConcurrency: config.maxConcurrency,
    });
  }

  if (!['/render', '/apply'].includes(url.pathname)) {
    return send(res, 404, { error: 'not_found', message: `No route for ${url.pathname}.` });
  }

  if (req.method !== 'POST') {
    return send(res, 405, { error: 'method_not_allowed', message: `${url.pathname} accepts POST.` });
  }

  if (!authorized(req)) {
    return send(res, 401, { error: 'unauthorized', message: 'Missing or invalid X-Automation-Token.' });
  }

  let payload;
  try {
    payload = await readJson(req);
  } catch (error) {
    return send(res, 400, { error: 'bad_request', message: error.message });
  }

  if (url.pathname === '/apply') {
    return handleApply(res, payload);
  }

  const target = validateTarget(payload?.url);
  if (target.error) {
    return send(res, 422, { error: 'invalid_url', message: target.error });
  }

  if (inFlight >= config.maxConcurrency) {
    // Shed load rather than queue: the caller is a queued Laravel job that can
    // be retried, and holding the connection open only risks its timeout.
    return send(res, 503, {
      error: 'busy',
      message: `All ${config.maxConcurrency} render slots are in use.`,
    });
  }

  inFlight += 1;

  try {
    const result = await renderPage({
      url: target.url,
      waitUntil: str(payload.waitUntil),
      waitForSelector: str(payload.waitForSelector),
      timeoutMs: payload.timeoutMs,
      settleMs: payload.settleMs,
      userAgent: str(payload.userAgent),
    });

    log('info', 'Rendered a page.', {
      url: result.url,
      status: result.status,
      bytes: Buffer.byteLength(result.html, 'utf8'),
      elapsedMs: result.elapsedMs,
    });

    return send(res, 200, result);
  } catch (error) {
    const status = error?.statusCode ?? 502;
    log('warn', 'Render failed.', { url: target.url, message: error?.message });

    return send(res, status, {
      error: 'render_failed',
      message: error?.message ?? 'The page could not be rendered.',
    });
  } finally {
    inFlight -= 1;
  }
}

/**
 * Same guards as `/render`, same order: validate everything before a browser is
 * touched (422), then shed load rather than queue (503), then execute.
 *
 * A step that fails mid-script is NOT an HTTP error: the adapter needs the
 * partial step list and the screenshots to decide between retry and
 * `needs_review`, so that comes back `200` with `status: "failed"` on the step
 * that broke. Only a navigation failure is a `502`, matching `/render`, because
 * nothing after it could have run.
 */
async function handleApply(res, payload) {
  const validated = validateApplyRequest(payload);
  if (validated.error) {
    return send(res, 422, { error: 'invalid_request', message: validated.error });
  }

  if (inFlight >= config.maxConcurrency) {
    return send(res, 503, {
      error: 'busy',
      message: `All ${config.maxConcurrency} browser slots are in use.`,
    });
  }

  inFlight += 1;

  try {
    const result = await executeApply(validated.request);
    const { navigationFailed, ...body } = result;

    log(navigationFailed ? 'warn' : 'info', 'Ran an apply script.', {
      url: result.url,
      finalUrl: result.finalUrl,
      status: result.status,
      steps: result.steps.length,
      failedStep: result.failedStep,
      screenshots: result.screenshots.length,
      elapsedMs: result.elapsedMs,
    });

    if (navigationFailed) {
      return send(res, 502, {
        error: 'navigation_failed',
        message: `Navigation failed at step ${result.failedStep}.`,
        ...body,
      });
    }

    return send(res, 200, body);
  } catch (error) {
    const status = error?.statusCode ?? 502;
    log('warn', 'Apply script failed.', { url: validated.request.url, message: error?.message });

    return send(res, status, {
      error: 'apply_failed',
      message: error?.message ?? 'The apply script could not be executed.',
    });
  } finally {
    inFlight -= 1;
  }
}

function authorized(req) {
  if (config.token === '') {
    return true;
  }

  const presented = req.headers['x-automation-token'];
  return typeof presented === 'string' && timingSafeEqual(presented, config.token);
}

function timingSafeEqual(a, b) {
  if (a.length !== b.length) {
    return false;
  }

  let mismatch = 0;
  for (let i = 0; i < a.length; i += 1) {
    mismatch |= a.charCodeAt(i) ^ b.charCodeAt(i);
  }

  return mismatch === 0;
}

async function readJson(req) {
  const chunks = [];
  let bytes = 0;

  for await (const chunk of req) {
    bytes += chunk.length;
    if (bytes > config.maxBodyBytes) {
      throw new Error(`Request body is too large (over ${config.maxBodyBytes} bytes).`);
    }
    chunks.push(chunk);
  }

  const raw = Buffer.concat(chunks).toString('utf8');

  if (raw.trim() === '') {
    throw new Error('Request body is empty; expected JSON.');
  }

  try {
    return JSON.parse(raw);
  } catch {
    throw new Error('Request body is not valid JSON.');
  }
}

function str(value) {
  return typeof value === 'string' && value.trim() !== '' ? value.trim() : undefined;
}

function send(res, status, body) {
  const payload = JSON.stringify(body);
  res.writeHead(status, {
    'Content-Type': 'application/json; charset=utf-8',
    'Content-Length': Buffer.byteLength(payload),
  });
  res.end(payload);
}

function log(level, message, context = {}) {
  const line = JSON.stringify({ ts: new Date().toISOString(), level, message, ...context });
  if (level === 'error' || level === 'warn') {
    process.stderr.write(`${line}\n`);
  } else {
    process.stdout.write(`${line}\n`);
  }
}

const boundToLoopback = ['127.0.0.1', 'localhost', '::1'].includes(config.host);

if (config.token === '' && !boundToLoopback) {
  log('error', 'Refusing to start: AUTOMATION_WORKER_TOKEN is required when not bound to loopback.', {
    host: config.host,
  });
  process.exit(1);
}

if (config.token === '') {
  log('warn', 'Starting with no shared token. Acceptable on loopback only.', { host: config.host });
}

server.listen(config.port, config.host, () => {
  log('info', 'Automation worker listening.', {
    host: config.host,
    port: config.port,
    authenticated: config.token !== '',
  });
});

for (const signal of ['SIGINT', 'SIGTERM']) {
  process.on(signal, () => {
    log('info', 'Shutting down.', { signal });
    server.close(() => {
      closeBrowser().finally(() => process.exit(0));
    });
  });
}

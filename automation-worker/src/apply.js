import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { randomUUID } from 'node:crypto';
import { config } from './config.js';
import { validateTarget } from './urlGuard.js';

/**
 * `/apply`: run a declarative step script inside ONE browser context.
 *
 * Why a step script and not a session API: nothing persists between calls to
 * this worker (each request gets a fresh context that is destroyed afterwards),
 * so a whole application — including a multi-page Workday flow — has to fit in a
 * single request lifetime. See README, "Apply automation: confirmed scope".
 *
 * The worker stays a dumb executor, exactly as it does for enrichment. It
 * reports, per step, what happened, and returns the final page plus screenshots.
 * It makes NO policy decisions: no bot-detection verdict, no `needs_review`
 * call, no retry strategy, no "should we abort" logic. All of that lives in the
 * PHP adapters, which already own the selector lists and the bot-wall markers.
 */

const BLOCKED_RESOURCE_TYPES = new Set(['image', 'media', 'font']);

const STEP_KINDS = new Set([
  'goto',
  'fill',
  'select',
  'check',
  'click',
  'upload',
  'waitFor',
  'readText',
  'screenshot',
]);

/**
 * Validate the whole request up front, before a browser is touched.
 *
 * All-or-nothing on purpose: a step script with a typo in step 14 must not half
 * submit a job application and then fail. A rejection here is a `422` and the
 * adapter's bug, not the site's.
 *
 * @param {unknown} payload
 * @returns {{request: object}|{error: string}}
 */
export function validateApplyRequest(payload) {
  if (payload === null || typeof payload !== 'object') {
    return { error: 'Request body must be a JSON object.' };
  }

  const target = validateTarget(payload.url);
  if (target.error) {
    return { error: target.error };
  }

  if (!Array.isArray(payload.steps) || payload.steps.length === 0) {
    return { error: '`steps` must be a non-empty array.' };
  }

  if (payload.steps.length > config.maxApplySteps) {
    return { error: `\`steps\` exceeds the ${config.maxApplySteps}-step ceiling.` };
  }

  const steps = [];
  let screenshotCount = 0;

  for (let index = 0; index < payload.steps.length; index += 1) {
    const result = validateStep(payload.steps[index], index);
    if (result.error) {
      return { error: result.error };
    }
    if (result.step.kind === 'screenshot') {
      screenshotCount += 1;
    }
    steps.push(result.step);
  }

  if (screenshotCount > config.maxApplyScreenshots) {
    return {
      error: `\`steps\` requests ${screenshotCount} screenshots; the ceiling is ${config.maxApplyScreenshots}.`,
    };
  }

  const viewport = validateViewport(payload.viewport);
  if (viewport.error) {
    return { error: viewport.error };
  }

  return {
    request: {
      url: target.url,
      steps,
      timeoutMs: clamp(payload.timeoutMs, config.navigationTimeoutMs),
      userAgent: optionalString(payload.userAgent) || config.userAgent || undefined,
      // Default OFF for apply, unlike `/render`: screenshots are the audit
      // trail (Req 9.5) and upload widgets are often icon-driven, so dropping
      // images would corrupt the evidence. Callers may still opt in.
      blockAssets: payload.blockAssets === undefined ? false : Boolean(payload.blockAssets),
      viewport: viewport.viewport,
    },
  };
}

function validateStep(raw, index) {
  const at = `steps[${index}]`;

  if (raw === null || typeof raw !== 'object') {
    return { error: `${at} must be an object.` };
  }

  const kind = optionalString(raw.kind);
  if (!kind || !STEP_KINDS.has(kind)) {
    return { error: `${at}.kind must be one of: ${[...STEP_KINDS].join(', ')}.` };
  }

  const step = { kind, timeoutMs: clamp(raw.timeoutMs, undefined) };
  const selector = optionalString(raw.selector);

  const needsSelector = ['fill', 'select', 'check', 'click', 'upload', 'readText'].includes(kind);
  if (needsSelector && !selector) {
    return { error: `${at}.selector is required for "${kind}".` };
  }
  step.selector = selector;

  switch (kind) {
    case 'goto': {
      const target = validateTarget(raw.url, { field: `${at}.url` });
      if (target.error) {
        return { error: target.error };
      }
      step.url = target.url;
      step.waitUntil = optionalString(raw.waitUntil) || config.waitUntil;
      break;
    }

    case 'fill': {
      if (typeof raw.value !== 'string') {
        return { error: `${at}.value must be a string.` };
      }
      step.value = raw.value;
      break;
    }

    case 'select': {
      const values = Array.isArray(raw.value) ? raw.value : [raw.value];
      if (values.length === 0 || values.some((value) => typeof value !== 'string')) {
        return { error: `${at}.value must be a string or an array of strings.` };
      }
      step.value = values;
      break;
    }

    case 'click':
      // Selector-only: already validated above.
      break;

    case 'check': {
      if (raw.checked !== undefined && typeof raw.checked !== 'boolean') {
        return { error: `${at}.checked must be a boolean.` };
      }
      step.checked = raw.checked ?? true;
      break;
    }

    case 'upload': {
      // Never inline bytes: a tailored PDF would blow the body cap and would sit
      // in request logs. The adapter passes a short-lived signed HTTPS URL
      // (S3StorageService already mints them) and the worker streams it to a
      // temp file for `setInputFiles`.
      const target = validateTarget(raw.url, { field: `${at}.url`, requireHttps: true });
      if (target.error) {
        return { error: target.error };
      }
      step.url = target.url;
      step.filename = sanitizeFilename(optionalString(raw.filename));
      break;
    }

    case 'waitFor': {
      const ms = clamp(raw.ms, undefined);
      if (!selector && ms === undefined) {
        return { error: `${at} requires either \`selector\` or \`ms\`.` };
      }
      step.ms = ms;
      step.state = optionalString(raw.state) || 'visible';
      if (!['attached', 'detached', 'visible', 'hidden'].includes(step.state)) {
        return { error: `${at}.state must be attached, detached, visible or hidden.` };
      }
      break;
    }

    case 'readText': {
      step.name = optionalString(raw.name);
      break;
    }

    case 'screenshot': {
      const name = optionalString(raw.name);
      if (!name) {
        return { error: `${at}.name is required for "screenshot".` };
      }
      step.name = sanitizeFilename(name);
      step.fullPage = raw.fullPage === undefined ? true : Boolean(raw.fullPage);
      break;
    }

    default:
      return { error: `${at}.kind is not executable.` };
  }

  return { step };
}

function validateViewport(value) {
  if (value === undefined || value === null) {
    // Desktop by default, same reasoning as `/render`: several ATS templates
    // serve a stripped mobile layout that hides parts of the form.
    return { viewport: { width: 1366, height: 900 } };
  }

  if (typeof value !== 'object') {
    return { error: '`viewport` must be an object with width and height.' };
  }

  const width = Number.parseInt(value.width ?? '', 10);
  const height = Number.parseInt(value.height ?? '', 10);

  if (!Number.isFinite(width) || !Number.isFinite(height) || width < 320 || height < 320) {
    return { error: '`viewport` width and height must each be at least 320.' };
  }

  return { viewport: { width: Math.min(width, 3840), height: Math.min(height, 3840) } };
}

/**
 * Execute a validated step script.
 *
 * Failure semantics, deliberately minimal:
 *  - A step that throws is recorded `failed` with the selector and the message;
 *    every later step is recorded `skipped`. Execution stops there, because
 *    typing into page 2 of a form that never loaded is worse than stopping.
 *  - The run still returns its artifacts (final URL, title, HTML, screenshots)
 *    so PHP can judge the page — including whether it hit a CAPTCHA or login
 *    wall. The worker does not judge.
 *  - A `goto` failure is surfaced as a navigation error (502 at the HTTP layer)
 *    because nothing downstream of it could have run.
 *
 * @param {object} request output of {@link validateApplyRequest}
 */
export async function executeApply(request) {
  const startedAt = Date.now();
  // Imported lazily so the validation above stays unit-testable without a
  // Chromium download, and so a browser is only launched once a request is
  // known to be well-formed.
  const { getBrowser } = await import('./browser.js');

  const browser = await getBrowser();
  const context = await browser.newContext({
    userAgent: request.userAgent,
    viewport: request.viewport,
    ignoreHTTPSErrors: true,
    javaScriptEnabled: true,
    acceptDownloads: false,
  });

  const steps = [];
  const screenshots = [];
  const tempFiles = [];
  let navigationFailed = false;
  let failure = null;

  try {
    if (request.blockAssets) {
      await context.route('**/*', (route) =>
        BLOCKED_RESOURCE_TYPES.has(route.request().resourceType()) ? route.abort() : route.continue()
      );
    }

    const page = await context.newPage();
    page.setDefaultTimeout(request.timeoutMs);

    let response = await page.goto(request.url, {
      waitUntil: config.waitUntil,
      timeout: request.timeoutMs,
    });

    for (let index = 0; index < request.steps.length; index += 1) {
      const step = request.steps[index];

      if (failure !== null) {
        steps.push({ index, kind: step.kind, status: 'skipped', selector: step.selector });
        continue;
      }

      const record = { index, kind: step.kind, status: 'ok' };
      if (step.selector) {
        record.selector = step.selector;
      }

      try {
        const outcome = await runStep(page, step, request, { screenshots, tempFiles });
        if (outcome?.response) {
          response = outcome.response;
        }
        if (outcome?.text !== undefined) {
          record.text = outcome.text;
        }
        if (outcome?.name !== undefined) {
          record.name = outcome.name;
        }
      } catch (error) {
        record.status = 'failed';
        record.error = truncate(error?.message ?? 'Step failed.', 500);
        failure = record.error;
        navigationFailed = step.kind === 'goto';
      }

      steps.push(record);
    }

    const html = await page.content().catch(() => '');

    return {
      url: request.url,
      finalUrl: page.url(),
      status: response ? response.status() : null,
      title: await page.title().catch(() => ''),
      html: Buffer.byteLength(html, 'utf8') > config.maxHtmlBytes ? '' : html,
      elapsedMs: Date.now() - startedAt,
      steps,
      screenshots,
      // Reported, not acted on. PHP decides what a failure means.
      failedStep: failure === null ? null : steps.find((entry) => entry.status === 'failed')?.index ?? null,
      navigationFailed,
    };
  } finally {
    await context.close().catch(() => undefined);
    await Promise.all(tempFiles.map((file) => fs.rm(file, { force: true }).catch(() => undefined)));
  }
}

async function runStep(page, step, request, state) {
  const timeout = step.timeoutMs ?? request.timeoutMs;

  switch (step.kind) {
    case 'goto': {
      const response = await page.goto(step.url, { waitUntil: step.waitUntil, timeout });
      return { response };
    }

    case 'fill':
      await page.fill(step.selector, step.value, { timeout });
      return {};

    case 'select':
      await page.selectOption(step.selector, step.value, { timeout });
      return {};

    case 'check':
      if (step.checked) {
        await page.check(step.selector, { timeout });
      } else {
        await page.uncheck(step.selector, { timeout });
      }
      return {};

    case 'click':
      await page.click(step.selector, { timeout });
      return {};

    case 'upload': {
      const file = await downloadToTempFile(step.url, step.filename);
      state.tempFiles.push(file);
      await page.setInputFiles(step.selector, file, { timeout });
      return {};
    }

    case 'waitFor':
      if (step.selector) {
        await page.waitForSelector(step.selector, { state: step.state, timeout });
      } else {
        await page.waitForTimeout(Math.min(step.ms, timeout));
      }
      return {};

    case 'readText': {
      const text = await page.textContent(step.selector, { timeout });
      return { text: truncate((text ?? '').trim(), config.maxApplyTextChars), name: step.name };
    }

    case 'screenshot': {
      const buffer = await page.screenshot({ fullPage: step.fullPage, type: 'png', timeout });
      state.screenshots.push({
        name: step.name,
        format: 'png',
        bytes: buffer.byteLength,
        base64: buffer.toString('base64'),
      });
      return { name: step.name };
    }

    default:
      throw new Error(`Unsupported step kind "${step.kind}".`);
  }
}

/**
 * Stream a signed URL to a temp file for `setInputFiles`.
 *
 * Capped and always cleaned up by the caller's `finally`: the worker holds a
 * user's resume on disk for the length of one request and no longer.
 */
export async function downloadToTempFile(url, filename) {
  const response = await fetch(url, { redirect: 'follow' });

  if (!response.ok) {
    throw new Error(`Upload source responded ${response.status}.`);
  }

  const declared = Number.parseInt(response.headers.get('content-length') ?? '', 10);
  if (Number.isFinite(declared) && declared > config.maxUploadBytes) {
    throw new Error(`Upload source is ${declared} bytes; the ceiling is ${config.maxUploadBytes}.`);
  }

  const body = Buffer.from(await response.arrayBuffer());
  if (body.byteLength > config.maxUploadBytes) {
    throw new Error(`Upload source is ${body.byteLength} bytes; the ceiling is ${config.maxUploadBytes}.`);
  }

  const dir = await fs.mkdtemp(path.join(os.tmpdir(), 'apply-upload-'));
  const name = sanitizeFilename(filename) || deriveFilename(url) || `${randomUUID()}.pdf`;
  const file = path.join(dir, name);
  await fs.writeFile(file, body);

  return file;
}

function deriveFilename(url) {
  try {
    return sanitizeFilename(path.basename(new URL(url).pathname));
  } catch {
    return undefined;
  }
}

function sanitizeFilename(value) {
  if (!value) {
    return undefined;
  }
  // Path separators and traversal are stripped rather than rejected: the name is
  // cosmetic (it is what the ATS shows), the safety is in writing into a fresh
  // mkdtemp directory.
  const cleaned = value.replace(/[^A-Za-z0-9._-]+/g, '_').replace(/^\.+/, '');
  return cleaned === '' ? undefined : cleaned.slice(0, 120);
}

function truncate(value, max) {
  return value.length > max ? `${value.slice(0, max)}…` : value;
}

function clamp(value, fallback) {
  const parsed = Number.parseInt(value ?? '', 10);
  if (!Number.isFinite(parsed) || parsed <= 0) {
    return fallback;
  }
  return Math.min(parsed, config.navigationTimeoutMs * 2);
}

function optionalString(value) {
  return typeof value === 'string' && value.trim() !== '' ? value.trim() : undefined;
}

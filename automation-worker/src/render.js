import { getBrowser } from './browser.js';
import { config } from './config.js';

const BLOCKED_RESOURCE_TYPES = new Set(['image', 'media', 'font']);

/**
 * Render one URL and hand back its post-JavaScript HTML.
 *
 * The worker deliberately does no extraction: selector lists, length floors and
 * bot-wall markers all live in `backend/config/enrichment.php` so that the plain
 * HTTP path and this path are judged by exactly the same rules. This returns
 * `document.documentElement.outerHTML` and nothing more.
 *
 * @param {{url: string, waitUntil?: string, waitForSelector?: string, timeoutMs?: number, settleMs?: number, userAgent?: string}} request
 */
export async function renderPage(request) {
  const startedAt = Date.now();
  const timeout = clamp(request.timeoutMs, config.navigationTimeoutMs);
  const settle = request.settleMs ?? config.settleMs;
  const userAgent = request.userAgent || config.userAgent || undefined;

  const browser = await getBrowser();
  const context = await browser.newContext({
    userAgent,
    // A desktop viewport: several ATS templates render a stripped mobile layout
    // that omits the full description.
    viewport: { width: 1366, height: 900 },
    ignoreHTTPSErrors: true,
    javaScriptEnabled: true,
  });

  try {
    if (config.blockAssets) {
      await context.route('**/*', (route) => {
        if (BLOCKED_RESOURCE_TYPES.has(route.request().resourceType())) {
          return route.abort();
        }
        return route.continue();
      });
    }

    const page = await context.newPage();
    page.setDefaultTimeout(timeout);

    const response = await page.goto(request.url, {
      waitUntil: request.waitUntil || config.waitUntil,
      timeout,
    });

    if (request.waitForSelector) {
      // Best effort: a posting whose expected container never appears is still
      // worth returning, since Laravel's selector list is broader than whatever
      // single hint it sent.
      await page
        .waitForSelector(request.waitForSelector, { timeout: Math.min(timeout, 10000) })
        .catch(() => undefined);
    } else if (settle > 0) {
      await page.waitForTimeout(Math.min(settle, timeout));
    }

    const html = await page.content();

    if (Buffer.byteLength(html, 'utf8') > config.maxHtmlBytes) {
      throw Object.assign(new Error('Rendered document exceeded the configured size ceiling.'), {
        statusCode: 422,
      });
    }

    return {
      url: request.url,
      finalUrl: page.url(),
      status: response ? response.status() : null,
      title: await page.title().catch(() => ''),
      html,
      elapsedMs: Date.now() - startedAt,
    };
  } finally {
    await context.close().catch(() => undefined);
  }
}

function clamp(value, fallback) {
  const parsed = Number.parseInt(value ?? '', 10);
  if (!Number.isFinite(parsed) || parsed <= 0) {
    return fallback;
  }
  // Never let a caller ask for an unbounded render; the ceiling is the
  // configured navigation timeout doubled.
  return Math.min(parsed, config.navigationTimeoutMs * 2);
}

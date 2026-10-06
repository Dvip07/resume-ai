import { chromium } from 'playwright';
import { config } from './config.js';

/**
 * One lazily-launched, shared Chromium process.
 *
 * Launching a browser costs ~300ms and a lot of memory, so it is launched on the
 * first render and kept for the life of the worker. Isolation happens one level
 * down: every render gets its own browser *context* (its own cookie jar, its own
 * storage), which is cheap to create and is discarded afterwards, so no target
 * site can observe state from a previous request.
 */

let browserPromise = null;

export async function getBrowser() {
  if (browserPromise === null) {
    browserPromise = chromium
      .launch({
        headless: config.headless,
        args: [
          // Required in most containers, where /dev/shm is 64MB.
          '--disable-dev-shm-usage',
          '--no-sandbox',
          '--disable-gpu',
        ],
      })
      .catch((error) => {
        // Do not cache a failed launch: a missing browser binary is fixable
        // (`npx playwright install chromium`) and the next request should retry.
        browserPromise = null;
        throw error;
      });
  }

  return browserPromise;
}

export async function closeBrowser() {
  if (browserPromise === null) {
    return;
  }

  const pending = browserPromise;
  browserPromise = null;

  try {
    const browser = await pending;
    await browser.close();
  } catch {
    // Shutting down; a browser that already died needs no closing.
  }
}

export function browserLaunched() {
  return browserPromise !== null;
}

/**
 * The one place that decides whether this worker is allowed to point a browser
 * at a URL.
 *
 * Shared by `/render` and `/apply` on purpose: an SSRF guard that exists twice
 * is a guard that drifts. It is a literal-address check only — it cannot stop a
 * public hostname that resolves inward, so network egress rules remain the real
 * control and this is the cheap guard against the obvious mistake.
 */

const PRIVATE_HOST_PATTERNS = [
  /^localhost$/i,
  /^127\./,
  /^0\.0\.0\.0$/,
  /^10\./,
  /^169\.254\./,
  /^192\.168\./,
  /^172\.(1[6-9]|2\d|3[01])\./,
  /^\[?::1\]?$/,
  /\.internal$/i,
  /\.local$/i,
];

/**
 * Read at call time rather than module load so a fixture-server test can flip
 * it without re-importing the module graph.
 */
export function allowPrivateHosts() {
  return ['1', 'true', 'yes', 'on'].includes(
    String(process.env.AUTOMATION_WORKER_ALLOW_PRIVATE_HOSTS ?? '').toLowerCase()
  );
}

/**
 * @param {unknown} value
 * @param {{field?: string, requireHttps?: boolean}} [options]
 * @returns {{url: string}|{error: string}}
 */
export function validateTarget(value, options = {}) {
  const field = options.field ?? 'url';

  if (typeof value !== 'string' || value.trim() === '') {
    return { error: `\`${field}\` is required.` };
  }

  let parsed;
  try {
    parsed = new URL(value.trim());
  } catch {
    return { error: `\`${field}\` is not an absolute URL.` };
  }

  const schemes = options.requireHttps && !allowPrivateHosts() ? ['https:'] : ['http:', 'https:'];

  if (!schemes.includes(parsed.protocol)) {
    return { error: `Unsupported scheme "${parsed.protocol}" for \`${field}\`.` };
  }

  if (!allowPrivateHosts() && PRIVATE_HOST_PATTERNS.some((pattern) => pattern.test(parsed.hostname))) {
    return { error: `Refusing to fetch a private/loopback address for \`${field}\`.` };
  }

  return { url: parsed.toString() };
}

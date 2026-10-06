import test from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import fs from 'node:fs/promises';
import { validateApplyRequest, downloadToTempFile } from '../src/apply.js';
import { validateTarget } from '../src/urlGuard.js';
import { config } from '../src/config.js';

/**
 * `node --test`, no test framework: the worker's dependency list is Playwright
 * and nothing else, and that is worth keeping for a process that runs a browser.
 *
 * These cover the half of `/apply` that decides things — request validation and
 * the upload download — without launching Chromium.
 */

const script = (steps) => ({ url: 'https://jobs.example.com/apply/1', steps });

test('a well-formed step script is accepted and normalised', () => {
  const result = validateApplyRequest(
    script([
      { kind: 'fill', selector: '#email', value: 'a@b.test' },
      { kind: 'select', selector: '#country', value: 'CA' },
      { kind: 'upload', selector: '#resume', url: 'https://s3.example.com/signed/resume.pdf' },
      { kind: 'screenshot', name: 'review page' },
    ])
  );

  assert.equal(result.error, undefined);
  assert.equal(result.request.steps.length, 4);
  // `select` always carries an array, so the executor has one shape to handle.
  assert.deepEqual(result.request.steps[1].value, ['CA']);
  assert.equal(result.request.steps[3].name, 'review_page');
  // Asset blocking is off by default for apply: screenshots are the audit trail.
  assert.equal(result.request.blockAssets, false);
  assert.deepEqual(result.request.viewport, { width: 1366, height: 900 });
  assert.equal(result.request.timeoutMs, config.navigationTimeoutMs);
});

test('asset blocking stays caller-controlled per request', () => {
  assert.equal(validateApplyRequest(script([{ kind: 'waitFor', ms: 500 }])).request.blockAssets, false);
  assert.equal(
    validateApplyRequest({ ...script([{ kind: 'waitFor', ms: 500 }]), blockAssets: true }).request
      .blockAssets,
    true
  );
});

test('steps must be a non-empty array', () => {
  assert.match(validateApplyRequest(script([])).error, /non-empty array/);
  assert.match(validateApplyRequest({ url: 'https://x.example.com' }).error, /non-empty array/);
});

test('an unknown step kind is rejected before a browser is launched', () => {
  assert.match(validateApplyRequest(script([{ kind: 'evaluate' }])).error, /steps\[0\]\.kind/);
});

test('selector-bearing steps require a selector', () => {
  for (const kind of ['fill', 'select', 'check', 'click', 'upload', 'readText']) {
    const result = validateApplyRequest(script([{ kind, value: 'x', url: 'https://s3.example.com/f' }]));
    assert.match(result.error, /selector is required/, `${kind} should require a selector`);
  }
});

test('fill requires a string value', () => {
  assert.match(validateApplyRequest(script([{ kind: 'fill', selector: '#a', value: 7 }])).error, /value/);
});

test('waitFor requires either a selector or a duration', () => {
  assert.match(validateApplyRequest(script([{ kind: 'waitFor' }])).error, /selector.+ms/);
  assert.equal(validateApplyRequest(script([{ kind: 'waitFor', ms: 250 }])).error, undefined);
});

test('screenshot requires a name, since the name is the audit-trail key', () => {
  assert.match(validateApplyRequest(script([{ kind: 'screenshot' }])).error, /name is required/);
});

test('uploads must be https URLs, never inline bytes', () => {
  assert.match(
    validateApplyRequest(script([{ kind: 'upload', selector: '#r', url: 'http://s3.example.com/f.pdf' }]))
      .error,
    /Unsupported scheme/
  );
  assert.match(
    validateApplyRequest(script([{ kind: 'upload', selector: '#r', url: 'not-a-url' }])).error,
    /absolute URL/
  );
});

test('private and loopback targets are refused', () => {
  assert.equal(validateApplyRequest(script([{ kind: 'click', selector: '#a' }])).error, undefined);
  assert.match(validateApplyRequest({ ...script([{ kind: 'click', selector: '#a' }]), url: 'http://127.0.0.1/x' }).error, /private\/loopback/);
  assert.match(
    validateApplyRequest(script([{ kind: 'goto', url: 'https://10.0.0.5/next' }])).error,
    /private\/loopback/
  );
});

test('the private-host guard is shared with /render', () => {
  assert.match(validateTarget('http://192.168.1.10/page').error, /private\/loopback/);
  assert.match(validateTarget('ftp://example.com/page').error, /Unsupported scheme/);
  assert.equal(validateTarget('https://example.com/page').url, 'https://example.com/page');
});

test('step and screenshot ceilings are enforced', () => {
  const many = Array.from({ length: config.maxApplySteps + 1 }, () => ({ kind: 'waitFor', ms: 10 }));
  assert.match(validateApplyRequest(script(many)).error, /-step ceiling/);

  const shots = Array.from({ length: config.maxApplyScreenshots + 1 }, (_, i) => ({
    kind: 'screenshot',
    name: `s${i}`,
  }));
  assert.match(validateApplyRequest(script(shots)).error, /ceiling is/);
});

test('a viewport override is validated and clamped', () => {
  assert.match(validateApplyRequest({ ...script([{ kind: 'waitFor', ms: 10 }]), viewport: { width: 10, height: 10 } }).error, /at least 320/);
  assert.deepEqual(
    validateApplyRequest({ ...script([{ kind: 'waitFor', ms: 10 }]), viewport: { width: 9999, height: 1000 } })
      .request.viewport,
    { width: 3840, height: 1000 }
  );
});

test('an upload URL is streamed to a temp file with a sanitised name', async () => {
  const body = Buffer.from('%PDF-1.7 tailored resume');
  const server = await serve((req, res) => {
    res.writeHead(200, { 'Content-Type': 'application/pdf', 'Content-Length': body.length });
    res.end(body);
  });

  try {
    const file = await downloadToTempFile(`${server.origin}/signed/resume.pdf`, '../../etc/pa ssw d.pdf');
    assert.match(file, /_.._etc_pa_ssw_d\.pdf$/);
    assert.deepEqual(await fs.readFile(file), body);
    await fs.rm(file, { force: true });
  } finally {
    await server.close();
  }
});

test('an oversized upload is refused by its declared length', async () => {
  const server = await serve((req, res) => {
    res.writeHead(200, { 'Content-Length': String(config.maxUploadBytes + 1) });
    res.end();
  });

  try {
    await assert.rejects(() => downloadToTempFile(`${server.origin}/big.pdf`), /ceiling is/);
  } finally {
    await server.close();
  }
});

test('a non-200 upload source is an error, not an empty file', async () => {
  const server = await serve((req, res) => {
    res.writeHead(403);
    res.end();
  });

  try {
    await assert.rejects(() => downloadToTempFile(`${server.origin}/expired.pdf`), /responded 403/);
  } finally {
    await server.close();
  }
});

function serve(handler) {
  const server = http.createServer(handler);
  return new Promise((resolve) => {
    server.listen(0, '127.0.0.1', () => {
      const { port } = server.address();
      resolve({
        origin: `http://127.0.0.1:${port}`,
        close: () => new Promise((done) => server.close(done)),
      });
    });
  });
}

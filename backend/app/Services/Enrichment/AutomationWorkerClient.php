<?php

namespace App\Services\Enrichment;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Laravel's side of the Node/Playwright automation worker (Requirement 3.4b,
 * task 9.2 — open decision #1 resolved in favour of Playwright over Panther).
 *
 * The worker is a separate process (`automation-worker/` at the repo root) that
 * this class talks to over plain HTTP on an internal address. That boundary is
 * the point of the decision: no ChromeDriver binary in `base_path()`, no
 * browser-driver package in `composer.json`, and a browser that can be
 * restarted, memory-capped and scaled without touching the API.
 *
 * Everything here is best effort. A worker that is disabled, unconfigured,
 * unreachable, busy or slow returns null with a log line — never an exception —
 * because the caller has already produced a usable outcome from the plain fetch
 * and a missing browser must not turn one stubborn posting into a failed job. In
 * dev and in CI the disabled path is the normal path.
 *
 * Note the asymmetry with the plain fetch on purpose: robots.txt is checked by
 * {@see JobEnrichmentService} *before* this is ever called, so a disallowed URL
 * never reaches a browser. The worker enforces no policy of its own.
 */
class AutomationWorkerClient
{
    /**
     * Is the fallback usable at all? Callers check this before doing any work to
     * set up a render, and it is what keeps the log quiet in the (default)
     * disabled case.
     */
    public function isConfigured(): bool
    {
        return (bool) config('services.automation_worker.enabled', false)
            && $this->baseUrl() !== '';
    }

    /**
     * Render `$url` in the worker's browser and return the resulting HTML.
     *
     * @param string|null $waitForSelector Optional hint: the container the plain
     *                                     fetch was hoping for. Only forwarded
     *                                     when `wait_for_selector` is enabled;
     *                                     the worker treats it as best effort.
     */
    public function render(string $url, ?string $waitForSelector = null): ?RenderedPage
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $response = $this->post($url, $waitForSelector);
        } catch (Throwable $e) {
            // Connection refused is the everyday case: the worker simply is not
            // running. Info, not error — the pipeline is unharmed.
            Log::info('Automation worker was unreachable; skipping the browser fallback.', [
                'url' => $url,
                'reason' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('Automation worker refused a render request.', [
                'url' => $url,
                'status' => $response->status(),
                // The worker answers with {error, message}; keep it short so a
                // rendered page can never end up in the log.
                'worker_error' => (string) ($response->json('error') ?? ''),
                'worker_message' => mb_substr((string) ($response->json('message') ?? ''), 0, 300),
            ]);

            return null;
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            Log::warning('Automation worker returned a non-JSON body.', ['url' => $url]);

            return null;
        }

        $page = RenderedPage::fromWorkerPayload($payload, $url);

        if ($page === null) {
            Log::warning('Automation worker returned no HTML.', ['url' => $url]);
        }

        return $page;
    }

    /** Liveness probe, for a status widget or a pre-flight check. */
    public function healthy(): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        try {
            return $this->request()
                ->timeout(max(1, (int) config('services.automation_worker.connect_timeout', 5)))
                ->get($this->baseUrl().$this->path('health_path', '/health'))
                ->successful();
        } catch (Throwable) {
            return false;
        }
    }

    protected function post(string $url, ?string $waitForSelector): \Illuminate\Http\Client\Response
    {
        return $this->request()->post(
            $this->baseUrl().$this->path('render_path', '/render'),
            $this->payload($url, $waitForSelector)
        );
    }

    protected function request(): \Illuminate\Http\Client\PendingRequest
    {
        $token = (string) config('services.automation_worker.token', '');

        $headers = ['Accept' => 'application/json'];

        if ($token !== '') {
            $headers['X-Automation-Token'] = $token;
        }

        return Http::withHeaders($headers)
            ->timeout((int) config('services.automation_worker.timeout', 45))
            ->connectTimeout((int) config('services.automation_worker.connect_timeout', 5));
    }

    /**
     * The render request body.
     *
     * The user agent is the enrichment one, not the worker's default, so a site
     * sees the same identity whichever path fetched it — the honest, contactable
     * string robots.txt rules are matched against.
     *
     * @return array<string, mixed>
     */
    protected function payload(string $url, ?string $waitForSelector): array
    {
        $payload = [
            'url' => $url,
            'userAgent' => (string) config('enrichment.user_agent'),
        ];

        if (($waitUntil = config('services.automation_worker.wait_until')) !== null && $waitUntil !== '') {
            $payload['waitUntil'] = (string) $waitUntil;
        }

        if (($settle = config('services.automation_worker.settle_ms')) !== null) {
            $payload['settleMs'] = (int) $settle;
        }

        // CSS only: the worker's `waitForSelector` is a browser API and cannot
        // take the XPath dialect the config list also allows.
        if ((bool) config('services.automation_worker.wait_for_selector', false)
            && is_string($waitForSelector)
            && $waitForSelector !== ''
            && ! str_starts_with($waitForSelector, 'xpath:')
            && ! str_starts_with($waitForSelector, '/')
            && ! str_starts_with($waitForSelector, '(')
        ) {
            $payload['waitForSelector'] = $waitForSelector;
        }

        return $payload;
    }

    protected function baseUrl(): string
    {
        return rtrim((string) config('services.automation_worker.base_url', ''), '/');
    }

    protected function path(string $key, string $default): string
    {
        $path = (string) config("services.automation_worker.{$key}", $default);
        $path = $path === '' ? $default : $path;

        return str_starts_with($path, '/') ? $path : '/'.$path;
    }
}

<?php

namespace App\Services\Apply;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Laravel's side of the automation worker's `POST /apply` endpoint
 * (Requirements 9.1 and 9.2, task 15.2).
 *
 * A sibling of {@see \App\Services\Enrichment\AutomationWorkerClient}: same
 * process, same `services.automation_worker` config block, same
 * `X-Automation-Token` shared secret. One worker address, one token, two
 * callers.
 *
 * Two things differ from the enrichment client, both deliberate:
 *
 * 1. **Nothing is swallowed into null.** Enrichment can degrade silently because
 *    the plain fetch already produced an answer. An application has no fallback,
 *    so every refusal comes back as a described {@see ApplyRunResult::unavailable()}
 *    and the adapter decides between `failed` (retry later) and `needs_review`.
 * 2. **Asset blocking is off and the timeout is longer.** Screenshots are the
 *    audit trail (Req 9.5) and upload widgets are icon-driven, so a stripped
 *    page is the wrong page; and a multi-page form outlives a single render.
 *
 * Still no exceptions: a disabled, unconfigured, unreachable, busy or slow
 * worker must not crash the queued job that called it.
 */
class ApplyWorkerClient
{
    /** Is the apply path usable at all? Adapters check before building a script. */
    public function isConfigured(): bool
    {
        return (bool) config('services.automation_worker.enabled', false)
            && $this->baseUrl() !== '';
    }

    /**
     * Run `$script` against `$url` in one fresh browser context.
     *
     * @param  ApplyStepScript|array<int, array<string, mixed>>  $script
     */
    public function run(string $url, ApplyStepScript|array $script, ?int $timeoutMs = null): ApplyRunResult
    {
        if (! $this->isConfigured()) {
            return ApplyRunResult::unavailable('Automation worker is disabled or unconfigured.');
        }

        $steps = $script instanceof ApplyStepScript ? $script->toArray() : array_values($script);

        if ($url === '' || $steps === []) {
            // Caught here rather than spending a round trip on a 422 the worker
            // would reject before touching a browser.
            return ApplyRunResult::unavailable('Refusing to call the automation worker with an empty apply script.');
        }

        try {
            $response = $this->request()->post(
                $this->baseUrl().$this->path('apply_path', '/apply'),
                $this->payload($url, $steps, $timeoutMs)
            );
        } catch (Throwable $e) {
            Log::warning('Automation worker was unreachable during an apply run.', [
                'url' => $url,
                'reason' => $e->getMessage(),
            ]);

            return ApplyRunResult::unavailable('Automation worker was unreachable: '.$e->getMessage());
        }

        if (! $response->successful()) {
            $error = (string) ($response->json('error') ?? '');
            $message = mb_substr((string) ($response->json('message') ?? ''), 0, 300);

            Log::warning('Automation worker refused an apply run.', [
                'url' => $url,
                'status' => $response->status(),
                'worker_error' => $error,
                'worker_message' => $message,
            ]);

            return ApplyRunResult::unavailable(
                $this->describeRefusal($response->status(), $error, $message),
                $response->status()
            );
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            Log::warning('Automation worker returned a non-JSON apply body.', ['url' => $url]);

            return ApplyRunResult::unavailable('Automation worker returned an unreadable response.', $response->status());
        }

        return ApplyRunResult::fromWorkerPayload($payload, $url);
    }

    /**
     * The apply request body.
     *
     * `blockAssets` is pinned false — the worker already defaults that way for
     * `/apply`, but an apply run must never inherit the enrichment setting.
     *
     * @param  array<int, array<string, mixed>>  $steps
     * @return array<string, mixed>
     */
    protected function payload(string $url, array $steps, ?int $timeoutMs): array
    {
        $payload = [
            'url' => $url,
            'steps' => $steps,
            'blockAssets' => false,
        ];

        $timeoutMs ??= config('services.automation_worker.apply_timeout_ms');

        if ($timeoutMs !== null && (int) $timeoutMs > 0) {
            $payload['timeoutMs'] = (int) $timeoutMs;
        }

        // A real desktop browser string: unlike enrichment, we are not asking a
        // site to identify a crawler, we are completing its own form.
        if (($userAgent = config('services.automation_worker.apply_user_agent')) !== null && $userAgent !== '') {
            $payload['userAgent'] = (string) $userAgent;
        }

        return $payload;
    }

    protected function request(): \Illuminate\Http\Client\PendingRequest
    {
        $token = (string) config('services.automation_worker.token', '');

        $headers = ['Accept' => 'application/json'];

        if ($token !== '') {
            $headers['X-Automation-Token'] = $token;
        }

        return Http::withHeaders($headers)
            ->timeout($this->applyTimeoutSeconds())
            ->connectTimeout((int) config('services.automation_worker.connect_timeout', 5));
    }

    /**
     * Laravel's timeout sits above the worker's own budget on purpose, so the
     * worker is the side that gives up first and can say why.
     */
    protected function applyTimeoutSeconds(): int
    {
        $configured = (int) config(
            'services.automation_worker.apply_timeout',
            (int) config('services.automation_worker.timeout', 45) * 4
        );

        return max(1, $configured);
    }

    /** Turn the worker's documented status codes into something a user can read. */
    protected function describeRefusal(int $status, string $error, string $message): string
    {
        $detail = $message !== '' ? $message : $error;

        return match ($status) {
            401 => 'Automation worker rejected the shared token.',
            422 => 'Automation worker rejected the apply script: '.($detail !== '' ? $detail : 'invalid request'),
            503 => 'Automation worker had no free browser slots.',
            502 => 'Automation worker could not open the application page.',
            default => 'Automation worker returned HTTP '.$status.($detail !== '' ? ': '.$detail : '.'),
        };
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

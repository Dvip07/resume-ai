<?php

namespace App\Services\Enrichment;

/**
 * One successful render returned by the Node/Playwright automation worker
 * (task 9.2).
 *
 * Deliberately thin: the worker's contract is "hand back the HTML after
 * JavaScript ran, and nothing else". Selector matching, the length floor and
 * bot-wall detection all stay in {@see JobEnrichmentService}, so a rendered page
 * is judged by exactly the same rules as a plain-fetched one. If extraction
 * logic ever leaked into the worker, the two paths would start disagreeing about
 * what counts as a description.
 */
final class RenderedPage
{
    /**
     * @param string   $html     The post-JavaScript document.
     * @param int|null $status   HTTP status of the navigation, when the worker saw one.
     * @param string   $finalUrl Where the browser ended up after redirects.
     * @param int|null $elapsedMs Render duration as measured by the worker.
     */
    public function __construct(
        public readonly string $html,
        public readonly ?int $status = null,
        public readonly string $finalUrl = '',
        public readonly ?int $elapsedMs = null,
    ) {
    }

    /** @param array<string, mixed> $payload The worker's JSON response body. */
    public static function fromWorkerPayload(array $payload, string $requestedUrl): ?self
    {
        $html = $payload['html'] ?? null;

        if (! is_string($html) || trim($html) === '') {
            return null;
        }

        $status = $payload['status'] ?? null;
        $finalUrl = $payload['finalUrl'] ?? null;
        $elapsed = $payload['elapsedMs'] ?? null;

        return new self(
            html: $html,
            status: is_numeric($status) ? (int) $status : null,
            finalUrl: is_string($finalUrl) && $finalUrl !== '' ? $finalUrl : $requestedUrl,
            elapsedMs: is_numeric($elapsed) ? (int) $elapsed : null,
        );
    }
}

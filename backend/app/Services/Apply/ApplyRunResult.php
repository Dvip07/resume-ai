<?php

namespace App\Services\Apply;

/**
 * One `POST /apply` round trip, as seen from PHP.
 *
 * This is the *mechanical* outcome — what the browser did — and deliberately not
 * an {@see ApplyResult}. Whether a run means "applied", "failed" or
 * "needs_review" is a policy call that belongs to the adapter, which weighs the
 * final URL, title, HTML and step log against its own knowledge of the ATS. The
 * worker makes no such judgement, and neither does this class.
 *
 * It also covers the cases where no run happened at all: the worker being
 * disabled, unreachable or over capacity. Those arrive as `$ok = false` with a
 * `$failureReason`, never as an exception, matching
 * {@see \App\Services\Enrichment\AutomationWorkerClient}'s contract.
 */
final class ApplyRunResult
{
    /**
     * @param  bool  $ok  Did the worker run the script and answer 2xx? Note that a
     *                    failing *step* still comes back `ok` — the run happened.
     * @param  array<int, ApplyStepOutcome>  $steps
     * @param  array<int, ApplyScreenshot>  $screenshots
     */
    private function __construct(
        public readonly bool $ok,
        public readonly ?string $failureReason = null,
        public readonly ?int $httpStatus = null,
        public readonly string $finalUrl = '',
        public readonly ?int $status = null,
        public readonly string $title = '',
        public readonly string $html = '',
        public readonly ?int $elapsedMs = null,
        public readonly array $steps = [],
        public readonly array $screenshots = [],
        public readonly ?ApplyStepOutcome $failedStep = null,
        public readonly bool $navigationFailed = false,
    ) {}

    /** @param array<string, mixed> $payload The worker's JSON response body. */
    public static function fromWorkerPayload(array $payload, string $requestedUrl): self
    {
        $steps = [];

        foreach (self::listOf($payload['steps'] ?? null) as $step) {
            $steps[] = ApplyStepOutcome::fromWorkerPayload($step);
        }

        $screenshots = [];

        foreach (self::listOf($payload['screenshots'] ?? null) as $shot) {
            if (($decoded = ApplyScreenshot::fromWorkerPayload($shot)) !== null) {
                $screenshots[] = $decoded;
            }
        }

        $failedStep = is_array($payload['failedStep'] ?? null)
            ? ApplyStepOutcome::fromWorkerPayload($payload['failedStep'])
            : null;

        // Belt and braces: older/odd payloads may report the failure only in the
        // step log, so derive it rather than claiming every step passed.
        if ($failedStep === null) {
            foreach ($steps as $step) {
                if ($step->failed()) {
                    $failedStep = $step;
                    break;
                }
            }
        }

        $finalUrl = $payload['finalUrl'] ?? null;

        return new self(
            ok: true,
            httpStatus: 200,
            finalUrl: is_string($finalUrl) && $finalUrl !== '' ? $finalUrl : $requestedUrl,
            status: is_numeric($payload['status'] ?? null) ? (int) $payload['status'] : null,
            title: is_string($payload['title'] ?? null) ? $payload['title'] : '',
            html: is_string($payload['html'] ?? null) ? $payload['html'] : '',
            elapsedMs: is_numeric($payload['elapsedMs'] ?? null) ? (int) $payload['elapsedMs'] : null,
            steps: $steps,
            screenshots: $screenshots,
            failedStep: $failedStep,
            navigationFailed: (bool) ($payload['navigationFailed'] ?? false),
        );
    }

    public static function unavailable(string $reason, ?int $httpStatus = null): self
    {
        return new self(ok: false, failureReason: $reason, httpStatus: $httpStatus);
    }

    /** Did every step in the script succeed? */
    public function allStepsSucceeded(): bool
    {
        return $this->ok && $this->failedStep === null && ! $this->navigationFailed;
    }

    /** Text captured by a `readText` step, by its `name`. */
    public function text(string $name): ?string
    {
        foreach ($this->steps as $step) {
            if ($step->name === $name && $step->text !== null) {
                return $step->text;
            }
        }

        return null;
    }

    public function screenshot(string $name): ?ApplyScreenshot
    {
        foreach ($this->screenshots as $shot) {
            if ($shot->name === $name) {
                return $shot;
            }
        }

        return null;
    }

    /** @return array<int, string> */
    public function screenshotNames(): array
    {
        return array_map(static fn (ApplyScreenshot $s): string => $s->name, $this->screenshots);
    }

    /**
     * The array entries of a worker list field, skipping anything that isn't a
     * map. Defensive because the response is parsed JSON from another process.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function listOf(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
    }
}

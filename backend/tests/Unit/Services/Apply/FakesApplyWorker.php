<?php

namespace Tests\Unit\Services\Apply;

use Illuminate\Support\Facades\Http;

/**
 * Worker-faking helpers shared by the Lever and Workday adapter tests.
 *
 * The fake reads the script it was actually posted and builds its response from
 * it, so step indices always match the script the adapter compiled rather than a
 * hand-counted guess that drifts the moment a field is added.
 */
trait FakesApplyWorker
{
    /** Text a `readText` step on the confirmation element reads back. */
    private string $confirmationText = 'Thank you for applying to Acme.';

    /** Text a `readText` step on the page body reads back. */
    private string $pageText = 'Apply to Acme';

    /**
     * @param  callable(array<int, array<string, mixed>>): array<string, mixed>  $respond
     */
    private function fakeWorker(callable $respond, string $worker): void
    {
        Http::fake([
            $worker => fn ($request) => Http::response($respond($request->data()['steps'])),
        ]);
    }

    /**
     * Every step `ok`, a screenshot for each screenshot step, text for each
     * readText step.
     *
     * @param  array<int, array<string, mixed>>  $steps
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function successBody(array $steps, array $overrides = []): array
    {
        return array_merge([
            'finalUrl' => $this->confirmedUrl(),
            'status' => 200,
            'title' => 'Application confirmed',
            'html' => '<html><body>Thank you for applying</body></html>',
            'elapsedMs' => 7310,
            'steps' => $this->stepOutcomes($steps),
            'screenshots' => $this->screenshotsFor($steps),
            'failedStep' => null,
            'navigationFailed' => false,
        ], $overrides);
    }

    /**
     * The run stopped at `$failAt`, with the page looking like whatever
     * `$overrides` says it looked like.
     *
     * @param  array<int, array<string, mixed>>  $steps
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function failedBody(array $steps, int $failAt, array $overrides = []): array
    {
        return $this->successBody($steps, array_merge([
            'steps' => $this->stepOutcomes($steps, $failAt),
            'screenshots' => $this->screenshotsFor($steps, $failAt),
            'failedStep' => $this->stepOutcomes($steps, $failAt)[$failAt],
            'title' => $this->pageText,
            'html' => '<html><body>'.$this->pageText.'</body></html>',
            'finalUrl' => $this->applyUrl(),
        ], $overrides));
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     * @return array<int, array<string, mixed>>
     */
    private function stepOutcomes(array $steps, ?int $failAt = null, string $error = 'Timeout 30000ms exceeded'): array
    {
        $outcomes = [];

        foreach ($steps as $index => $step) {
            $status = match (true) {
                $failAt === null => 'ok',
                $index < $failAt => 'ok',
                $index === $failAt => 'failed',
                default => 'skipped',
            };

            $outcome = [
                'index' => $index,
                'kind' => $step['kind'],
                'status' => $status,
                'selector' => $step['selector'] ?? null,
                'name' => $step['name'] ?? null,
            ];

            if ($status === 'failed') {
                $outcome['error'] = $error;
            }

            if ($step['kind'] === 'readText' && $status === 'ok') {
                // A confirmation banner and the page body say very different
                // things, and conflating them would let a pre-submit body read
                // masquerade as proof the application landed.
                $outcome['text'] = str_ends_with((string) $step['name'], '_confirmation')
                    ? $this->confirmationText
                    : $this->pageText;
            }

            $outcomes[] = $outcome;
        }

        return $outcomes;
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     * @return array<int, array<string, mixed>>
     */
    private function screenshotsFor(array $steps, ?int $failAt = null): array
    {
        $shots = [];

        foreach ($steps as $index => $step) {
            if ($step['kind'] !== 'screenshot' || ($failAt !== null && $index > $failAt)) {
                continue;
            }

            $shots[] = [
                'name' => $step['name'],
                'format' => 'png',
                'base64' => base64_encode('PNG:'.$step['name']),
            ];
        }

        return $shots;
    }

    /** @param array<int, array<string, mixed>> $steps */
    private function indexOf(array $steps, string $kind): int
    {
        foreach ($steps as $index => $step) {
            if ($step['kind'] === $kind) {
                return $index;
            }
        }

        return -1;
    }

    /**
     * The confirmation wait is the last `waitFor` in every script here.
     *
     * @param  array<int, array<string, mixed>>  $steps
     */
    private function lastWaitForIndex(array $steps): int
    {
        $last = -1;

        foreach ($steps as $index => $step) {
            if ($step['kind'] === 'waitFor') {
                $last = $index;
            }
        }

        return $last;
    }

    /** @param array<int, array<string, mixed>> $steps
     *  @return array<int, mixed> */
    private function valuesOf(array $steps, string $kind, string $field = 'value'): array
    {
        $values = [];

        foreach ($steps as $step) {
            if ($step['kind'] === $kind) {
                $values[] = $step[$field] ?? null;
            }
        }

        return $values;
    }

    abstract private function applyUrl(): string;

    abstract private function confirmedUrl(): string;
}

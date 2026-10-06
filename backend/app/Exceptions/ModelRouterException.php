<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Typed failure for every unrecoverable problem inside ModelRouterService.
 *
 * Calling jobs catch this to mark their pipeline stage `failed` with a stored
 * reason instead of letting an uncaught throwable stall the chain
 * (design.md "Error handling", Requirement 4.5).
 *
 * The context carried here (task type, tier, model, HTTP status, raw body
 * excerpt) is what makes a failure diagnosable after the fact, so keep it
 * populated on every construction path.
 */
class ModelRouterException extends RuntimeException
{
    /**
     * HTTP statuses that mean "this request is wrong / this account cannot
     * make it", not "this model is having a bad time". Every model in the tier
     * would reject the exact same payload with the exact same status, so
     * retrying elsewhere only burns wall-clock time on a doomed job
     * (Requirement 4.5 asks for fallback on failure, not for pointless retries):
     *
     *   400 malformed request  — our payload is bad; fix the code, not the model
     *   401 invalid API key    — same key is used for every candidate
     *   402 insufficient credits — account-level, not model-level
     *   403 forbidden          — key lacks permission for this account/route
     *
     * Everything else (404/408/409/429/5xx, timeouts, connection errors, and
     * a model returning empty or non-JSON content) is model-specific or
     * transient and is exactly what a same-tier fallback exists for. Note 404
     * is deliberately retryable: it usually means a model slug was retired,
     * which the next candidate can cover.
     */
    private const TERMINAL_STATUSES = [400, 401, 402, 403];

    /**
     * @param array<string, mixed> $context
     * @param bool                 $retryable whether another model in the same
     *                                        tier is worth trying
     */
    public function __construct(
        string $message,
        public readonly array $context = [],
        ?\Throwable $previous = null,
        public readonly bool $retryable = false,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * True when the failure is plausibly specific to the model or the moment,
     * so falling through to the next candidate in the tier can help.
     */
    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    /**
     * Return the same failure with the full per-attempt history attached.
     *
     * Used when a terminal failure aborts the fallback walk: the message and
     * previous exception stay as-is (so callers keep the precise reason) while
     * the context gains the attempts already made.
     *
     * @param array<int, array<string, mixed>> $attempts
     */
    public function withAttemptHistory(array $attempts): self
    {
        return new self(
            $this->getMessage(),
            $this->context + ['attempts' => $attempts],
            $this->getPrevious(),
            $this->retryable,
        );
    }

    public static function missingApiKey(string $taskType): self
    {
        return new self(
            'OpenRouter API key is not configured; set OPENROUTER_API_KEY.',
            ['task_type' => $taskType]
        );
    }

    public static function noModelsConfigured(string $taskType, string $tier): self
    {
        return new self(
            "No OpenRouter models configured for tier [{$tier}].",
            ['task_type' => $taskType, 'tier' => $tier]
        );
    }

    public static function unknownTier(string $taskType, string $tier): self
    {
        return new self(
            "Unknown OpenRouter tier [{$tier}].",
            ['task_type' => $taskType, 'tier' => $tier]
        );
    }

    public static function transportFailure(
        string $taskType,
        string $tier,
        string $model,
        \Throwable $previous,
    ): self {
        return new self(
            "OpenRouter request failed for model [{$model}]: {$previous->getMessage()}",
            ['task_type' => $taskType, 'tier' => $tier, 'model' => $model],
            $previous,
            // Timeouts and connection errors are the canonical fallback case.
            retryable: true,
        );
    }

    public static function httpFailure(
        string $taskType,
        string $tier,
        string $model,
        int $status,
        string $body,
    ): self {
        return new self(
            "OpenRouter returned HTTP {$status} for model [{$model}].",
            [
                'task_type' => $taskType,
                'tier' => $tier,
                'model' => $model,
                'status' => $status,
                'body' => self::excerpt($body),
            ],
            retryable: ! in_array($status, self::TERMINAL_STATUSES, true),
        );
    }

    public static function emptyCompletion(string $taskType, string $tier, string $model, string $body): self
    {
        return new self(
            "OpenRouter returned no completion content for model [{$model}].",
            [
                'task_type' => $taskType,
                'tier' => $tier,
                'model' => $model,
                'body' => self::excerpt($body),
            ],
            // A model that produced nothing may just have been truncated or
            // filtered; another one in the tier can still answer.
            retryable: true,
        );
    }

    public static function unparseableJson(string $taskType, string $tier, string $model, string $content): self
    {
        return new self(
            "Could not extract JSON from the completion returned by model [{$model}].",
            [
                'task_type' => $taskType,
                'tier' => $tier,
                'model' => $model,
                'content' => self::excerpt($content),
            ],
            // Schema compliance varies by model, so this is worth re-asking
            // elsewhere in the tier.
            retryable: true,
        );
    }

    /**
     * Raised once every candidate model in the tier has been exhausted.
     *
     * Carries the whole attempt history (model, attempt number, status, error)
     * so the failure is diagnosable from one log line or one stored reason,
     * rather than only showing whatever the last model happened to say
     * (Requirement 4.5).
     *
     * @param array<int, array<string, mixed>> $attempts
     */
    public static function allCandidatesFailed(
        string $taskType,
        string $tier,
        array $attempts,
        ?\Throwable $previous = null,
    ): self {
        $models = array_values(array_unique(array_column($attempts, 'model')));
        $lastError = $previous?->getMessage() ?? 'unknown error';

        return new self(
            sprintf(
                'All %d OpenRouter candidate model(s) in tier [%s] failed for task [%s] '
                . 'after %d attempt(s): %s. Last error: %s',
                count($models),
                $tier,
                $taskType,
                count($attempts),
                implode(', ', $models),
                $lastError
            ),
            [
                'task_type' => $taskType,
                'tier' => $tier,
                'models' => $models,
                'attempts' => $attempts,
                'last_error' => $lastError,
            ],
            $previous
        );
    }

    private static function excerpt(string $raw): string
    {
        return \Illuminate\Support\Str::limit($raw, 500);
    }
}

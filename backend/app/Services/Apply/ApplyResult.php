<?php

namespace App\Services\Apply;

/**
 * The outcome of one {@see ApplyAdapter::apply()} call (design.md §7,
 * Requirement 9.2).
 *
 * Three statuses, and the distinction matters to the user:
 *
 * - `applied`      — the form was submitted and the page confirmed it.
 * - `failed`       — something broke that a retry might survive (a selector
 *                    moved, the worker was busy, the page timed out).
 * - `needs_review` — the run stopped on purpose and a human has to finish:
 *                    a CAPTCHA or login wall (Req 9.6), an unanswered screening
 *                    question, or no adapter for the URL at all.
 *
 * Adapters never throw to signal failure; exceptions are converted here by the
 * caller so one bad posting cannot take down the queue worker. Screenshots are
 * carried as S3 keys and are the audit trail (Req 9.5) — they are attached to
 * failures too, because that is when someone will want to look.
 */
final class ApplyResult
{
    public const STATUS_APPLIED = 'applied';

    public const STATUS_FAILED = 'failed';

    public const STATUS_NEEDS_REVIEW = 'needs_review';

    /**
     * @param  string  $status  One of the STATUS_* constants.
     * @param  string|null  $failureReason  Short, user-facing reason. Null on success.
     * @param  array<int, string>  $screenshotPaths  S3 keys, in capture order.
     * @param  array<int, string>  $unansweredQuestions  Screening questions the adapter could not answer.
     * @param  string|null  $confirmationUrl  Where the browser ended up, for the audit trail.
     * @param  array<string, mixed>  $metadata  Adapter-specific detail (step log, titles, timings).
     */
    private function __construct(
        public readonly string $status,
        public readonly ?string $failureReason = null,
        public readonly array $screenshotPaths = [],
        public readonly array $unansweredQuestions = [],
        public readonly ?string $confirmationUrl = null,
        public readonly array $metadata = [],
    ) {}

    /**
     * @param  array<int, string>  $screenshotPaths
     * @param  array<string, mixed>  $metadata
     */
    public static function applied(
        array $screenshotPaths = [],
        ?string $confirmationUrl = null,
        array $metadata = [],
    ): self {
        return new self(
            status: self::STATUS_APPLIED,
            screenshotPaths: array_values($screenshotPaths),
            confirmationUrl: $confirmationUrl,
            metadata: $metadata,
        );
    }

    /**
     * @param  array<int, string>  $screenshotPaths
     * @param  array<string, mixed>  $metadata
     */
    public static function failed(
        string $failureReason,
        array $screenshotPaths = [],
        ?string $confirmationUrl = null,
        array $metadata = [],
    ): self {
        return new self(
            status: self::STATUS_FAILED,
            failureReason: $failureReason,
            screenshotPaths: array_values($screenshotPaths),
            confirmationUrl: $confirmationUrl,
            metadata: $metadata,
        );
    }

    /**
     * @param  array<int, string>  $unansweredQuestions
     * @param  array<int, string>  $screenshotPaths
     * @param  array<string, mixed>  $metadata
     */
    public static function needsReview(
        string $reason,
        array $unansweredQuestions = [],
        array $screenshotPaths = [],
        ?string $confirmationUrl = null,
        array $metadata = [],
    ): self {
        return new self(
            status: self::STATUS_NEEDS_REVIEW,
            failureReason: $reason,
            screenshotPaths: array_values($screenshotPaths),
            unansweredQuestions: array_values($unansweredQuestions),
            confirmationUrl: $confirmationUrl,
            metadata: $metadata,
        );
    }

    public function isApplied(): bool
    {
        return $this->status === self::STATUS_APPLIED;
    }

    public function needsHumanReview(): bool
    {
        return $this->status === self::STATUS_NEEDS_REVIEW;
    }

    /** True when a retry could plausibly succeed — only `failed` qualifies. */
    public function isRetryable(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /** Same result with extra screenshot keys appended, once they are persisted. */
    public function withScreenshotPaths(array $paths): self
    {
        return new self(
            status: $this->status,
            failureReason: $this->failureReason,
            screenshotPaths: array_values(array_merge($this->screenshotPaths, array_values($paths))),
            unansweredQuestions: $this->unansweredQuestions,
            confirmationUrl: $this->confirmationUrl,
            metadata: $this->metadata,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'failure_reason' => $this->failureReason,
            'screenshot_paths' => $this->screenshotPaths,
            'unanswered_questions' => $this->unansweredQuestions,
            'confirmation_url' => $this->confirmationUrl,
            'metadata' => $this->metadata,
        ];
    }
}

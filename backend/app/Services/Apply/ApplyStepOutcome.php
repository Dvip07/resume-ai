<?php

namespace App\Services\Apply;

/**
 * What happened to one step of the script the worker ran.
 *
 * The worker reports the first failure with its selector and message, then marks
 * every later step `skipped`. Adapters use that to say *where* an application
 * stopped, which is the difference between a useful `needs_review` note and
 * "something went wrong".
 */
final class ApplyStepOutcome
{
    public const STATUS_OK = 'ok';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    public function __construct(
        public readonly int $index,
        public readonly string $kind,
        public readonly string $status,
        public readonly ?string $selector = null,
        public readonly ?string $error = null,
        public readonly ?string $text = null,
        public readonly ?string $name = null,
    ) {}

    /** @param array<string, mixed> $payload One entry of the worker's `steps[]`. */
    public static function fromWorkerPayload(array $payload): self
    {
        return new self(
            index: is_numeric($payload['index'] ?? null) ? (int) $payload['index'] : -1,
            kind: self::str($payload['kind'] ?? null) ?? '',
            status: self::str($payload['status'] ?? null) ?? self::STATUS_FAILED,
            selector: self::str($payload['selector'] ?? null),
            error: self::str($payload['error'] ?? null),
            text: is_string($payload['text'] ?? null) ? $payload['text'] : null,
            name: self::str($payload['name'] ?? null),
        );
    }

    public function failed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    private static function str(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}

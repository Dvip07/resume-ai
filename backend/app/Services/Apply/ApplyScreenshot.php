<?php

namespace App\Services\Apply;

/**
 * One screenshot the worker captured, decoded from the inline base64 PNG it
 * returns. Persisting it is a separate concern (S3, task 15.6); this type only
 * carries the bytes and the sanitised name the worker keyed it by.
 */
final class ApplyScreenshot
{
    public function __construct(
        public readonly string $name,
        public readonly string $bytes,
        public readonly string $format = 'png',
    ) {}

    /** @param array<string, mixed> $payload One entry of the worker's `screenshots[]`. */
    public static function fromWorkerPayload(array $payload): ?self
    {
        $base64 = $payload['base64'] ?? null;

        if (! is_string($base64) || $base64 === '') {
            return null;
        }

        $bytes = base64_decode($base64, true);

        if ($bytes === false || $bytes === '') {
            return null;
        }

        $name = $payload['name'] ?? null;
        $format = $payload['format'] ?? null;

        return new self(
            name: is_string($name) && $name !== '' ? $name : 'screenshot',
            bytes: $bytes,
            format: is_string($format) && $format !== '' ? $format : 'png',
        );
    }

    public function byteLength(): int
    {
        return strlen($this->bytes);
    }

    public function filename(): string
    {
        return $this->name.'.'.$this->format;
    }
}

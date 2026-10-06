<?php

namespace App\Services;

/**
 * One successful completion, plus the accounting the usage log needs
 * (task 7.5) and the parsed payload callers actually want.
 */
class ModelCompletionResult
{
    /**
     * @param array<mixed> $parsedJson decoded object when a JSON schema was
     *                                 requested; empty array otherwise
     */
    public function __construct(
        public readonly string $content,
        public readonly array $parsedJson,
        public readonly string $modelUsed,
        public readonly string $tierUsed,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly float $estimatedCostUsd,
    ) {}

    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }
}

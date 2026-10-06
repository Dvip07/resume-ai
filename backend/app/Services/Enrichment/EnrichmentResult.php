<?php

namespace App\Services\Enrichment;

use App\Enums\EnrichmentOutcome;

/**
 * The outcome of one {@see JobEnrichmentService::fetchDescription()} call
 * (task 9.1).
 *
 * Immutable and free of Eloquent: the service reads a URL and returns text, and
 * the queued `EnrichJobDescription` job (task 9.3) owns the decision of what to
 * write to `job_listings`. That split is what makes the extraction testable
 * without a database.
 *
 * `description` is non-null on {@see EnrichmentOutcome::Success} and *may* be
 * non-null on {@see EnrichmentOutcome::NoContent}: in the latter case it holds
 * the longest below-threshold candidate found, which the browser fallback
 * (task 9.2) can compare its own result against instead of starting blind.
 */
final class EnrichmentResult
{
    public const VIA_HTTP = 'http';

    public const VIA_BROWSER = 'browser';

    /**
     * @param string|null $description Plain text, or the best short candidate on NoContent.
     * @param string|null $selector    Which configured selector produced the text.
     * @param string|null $reason      Human-readable cause, for logs and `needs_review` messages.
     * @param int|null    $httpStatus  Status of the page fetch, when one happened.
     * @param string      $via         Which path produced this: `http` (plain fetch,
     *                                 task 9.1) or `browser` (the automation
     *                                 worker, task 9.2). Worth recording because
     *                                 a description that needed a browser is a
     *                                 signal about the site, and because the two
     *                                 paths cost very different amounts.
     */
    private function __construct(
        public readonly EnrichmentOutcome $outcome,
        public readonly ?string $description = null,
        public readonly ?string $selector = null,
        public readonly ?string $reason = null,
        public readonly ?int $httpStatus = null,
        public readonly string $via = self::VIA_HTTP,
    ) {
    }

    public static function success(string $description, string $selector, ?int $httpStatus = null): self
    {
        return new self(
            outcome: EnrichmentOutcome::Success,
            description: $description,
            selector: $selector,
            httpStatus: $httpStatus,
        );
    }

    public static function robotsDisallowed(string $reason): self
    {
        return new self(outcome: EnrichmentOutcome::RobotsDisallowed, reason: $reason);
    }

    public static function blocked(string $reason, ?int $httpStatus = null): self
    {
        return new self(outcome: EnrichmentOutcome::Blocked, reason: $reason, httpStatus: $httpStatus);
    }

    public static function noContent(
        string $reason,
        ?string $bestEffort = null,
        ?string $selector = null,
        ?int $httpStatus = null,
    ): self {
        return new self(
            outcome: EnrichmentOutcome::NoContent,
            description: $bestEffort,
            selector: $selector,
            reason: $reason,
            httpStatus: $httpStatus,
        );
    }

    public static function fetchFailed(string $reason, ?int $httpStatus = null): self
    {
        return new self(outcome: EnrichmentOutcome::FetchFailed, reason: $reason, httpStatus: $httpStatus);
    }

    public static function invalidUrl(string $reason): self
    {
        return new self(outcome: EnrichmentOutcome::InvalidUrl, reason: $reason);
    }

    /**
     * The same result, attributed to the browser fallback (task 9.2).
     *
     * A copy rather than a mutation because the plain-fetch result it is derived
     * from is still needed: the fallback compares the two and keeps the better
     * one, so neither may be altered in place.
     */
    public function viaBrowser(): self
    {
        return new self(
            outcome: $this->outcome,
            description: $this->description,
            selector: $this->selector,
            reason: $this->reason,
            httpStatus: $this->httpStatus,
            via: self::VIA_BROWSER,
        );
    }

    public function successful(): bool
    {
        return $this->outcome === EnrichmentOutcome::Success;
    }

    public function usedBrowser(): bool
    {
        return $this->via === self::VIA_BROWSER;
    }

    /** Length of whatever text this result carries, best-effort included. */
    public function length(): int
    {
        return $this->description === null ? 0 : mb_strlen($this->description);
    }

    /** @return array<string, mixed> Log-friendly summary; never the whole description. */
    public function context(): array
    {
        return [
            'outcome' => $this->outcome->value,
            'via' => $this->via,
            'selector' => $this->selector,
            'reason' => $this->reason,
            'http_status' => $this->httpStatus,
            'length' => $this->length(),
        ];
    }
}

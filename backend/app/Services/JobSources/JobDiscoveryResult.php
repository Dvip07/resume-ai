<?php

namespace App\Services\JobSources;

use Throwable;

/**
 * What one {@see JobDiscoveryOrchestrator} run did.
 *
 * Exists because the run is deliberately fault-tolerant: a dead provider is
 * logged and skipped rather than failing the fan-out (design.md, "Provider
 * rate-limit/budget exhaustion"), so the return value has to carry enough
 * detail for the caller — the queued `DiscoverJobsForUser` job (task 8.9), a
 * manual `POST /api/jobs/discover`, or a test — to tell "no jobs today" apart
 * from "every source was down".
 *
 * Counts are of *rows*, not of provider results:
 *  - `created`   new `job_listings` rows, each at `pipeline_stage = discovered`
 *  - `updated`   existing rows matched by the dedupe rules and gap-filled
 *  - `skipped`   results that couldn't become a row (unusable payload, or a
 *                provider returning something that isn't a NormalizedJob)
 *
 * `created + updated + skipped` therefore equals the number of results seen
 * across all providers that answered. `updated` counts duplicates, whether the
 * duplicate came from an earlier run, an earlier provider in the same fan-out,
 * or twice from one provider.
 */
class JobDiscoveryResult
{
    /** @var list<int> `job_listings.id` of every row created by this run. */
    private array $createdIds = [];

    /** @var list<int> `job_listings.id` of every existing row this run matched. */
    private array $updatedIds = [];

    private int $skipped = 0;

    /** @var array<string, array{created: int, updated: int, skipped: int}> */
    private array $perProvider = [];

    /** @var array<string, string> source key => failure message */
    private array $failures = [];

    /** @var array<string, int|null> source key => the daily limit it hit */
    private array $rateLimited = [];

    /**
     * Register a provider as part of this run before it is called, so a source
     * that answered with nothing is visible in {@see providerKeys()} instead of
     * being indistinguishable from one that was never enabled.
     */
    public function registerProvider(string $sourceKey): void
    {
        $this->perProvider[$sourceKey] ??= ['created' => 0, 'updated' => 0, 'skipped' => 0];
    }

    public function recordCreated(string $sourceKey, int $jobListingId): void
    {
        $this->createdIds[] = $jobListingId;
        $this->bump($sourceKey, 'created');
    }

    public function recordUpdated(string $sourceKey, int $jobListingId): void
    {
        $this->updatedIds[] = $jobListingId;
        $this->bump($sourceKey, 'updated');
    }

    public function recordSkipped(string $sourceKey): void
    {
        $this->skipped++;
        $this->bump($sourceKey, 'skipped');
    }

    /**
     * A provider that threw. Its results (if any) are discarded; the run
     * continues with the remaining sources.
     */
    public function recordProviderFailure(string $sourceKey, Throwable|string $reason): void
    {
        $this->registerProvider($sourceKey);
        $this->failures[$sourceKey] = $reason instanceof Throwable ? $reason->getMessage() : $reason;
    }

    /**
     * A provider that was not called because its daily budget was already spent
     * (Requirement 3.6). Kept apart from {@see recordProviderFailure()} on
     * purpose: an exhausted source is a normal end to the day, not a fault, so
     * it must not make {@see allProvidersFailed()} — the "something is
     * misconfigured" signal — fire.
     */
    public function recordRateLimited(string $sourceKey, ?int $dailyLimit = null): void
    {
        $this->registerProvider($sourceKey);
        $this->rateLimited[$sourceKey] = $dailyLimit;
    }

    public function created(): int
    {
        return count($this->createdIds);
    }

    public function updated(): int
    {
        return count($this->updatedIds);
    }

    public function skipped(): int
    {
        return $this->skipped;
    }

    /** Every result seen from every provider that answered. */
    public function seen(): int
    {
        return $this->created() + $this->updated() + $this->skipped;
    }

    /** @return list<int> */
    public function createdIds(): array
    {
        return $this->createdIds;
    }

    /** @return list<int> */
    public function updatedIds(): array
    {
        return $this->updatedIds;
    }

    /**
     * Every row this run touched — what the enrichment/scoring chain (task 8.9,
     * 9.4) walks. Created rows come first, in discovery order.
     *
     * @return list<int>
     */
    public function touchedIds(): array
    {
        return array_values(array_unique([...$this->createdIds, ...$this->updatedIds]));
    }

    /** @return list<string> keys of the providers this run fanned out to */
    public function providerKeys(): array
    {
        return array_keys($this->perProvider);
    }

    /** @return array<string, array{created: int, updated: int, skipped: int}> */
    public function perProvider(): array
    {
        return $this->perProvider;
    }

    /** @return array<string, string> */
    public function failures(): array
    {
        return $this->failures;
    }

    /** @return list<string> */
    public function failedProviderKeys(): array
    {
        return array_keys($this->failures);
    }

    public function hasFailures(): bool
    {
        return $this->failures !== [];
    }

    /** @return array<string, int|null> source key => the daily limit it hit */
    public function rateLimited(): array
    {
        return $this->rateLimited;
    }

    /** @return list<string> providers skipped for budget rather than called */
    public function rateLimitedProviderKeys(): array
    {
        return array_keys($this->rateLimited);
    }

    public function hasRateLimited(): bool
    {
        return $this->rateLimited !== [];
    }

    public function wasRateLimited(string $sourceKey): bool
    {
        return array_key_exists($sourceKey, $this->rateLimited);
    }

    /**
     * True when every provider in the fan-out threw. Distinct from an empty
     * run: it means discovery learned nothing, which is worth alerting on
     * (misconfigured credentials) where "no new jobs" is routine.
     */
    public function allProvidersFailed(): bool
    {
        return $this->perProvider !== [] && count($this->failures) === count($this->perProvider);
    }

    /** @return array<string, mixed> shape for log context */
    public function toArray(): array
    {
        return [
            'created' => $this->created(),
            'updated' => $this->updated(),
            'skipped' => $this->skipped,
            'providers' => $this->perProvider,
            'failures' => $this->failures,
            'rate_limited' => $this->rateLimited,
        ];
    }

    private function bump(string $sourceKey, string $bucket): void
    {
        $this->registerProvider($sourceKey);
        $this->perProvider[$sourceKey][$bucket]++;
    }
}

<?php

namespace App\Services\JobSources;

use Illuminate\Support\Collection;

/**
 * One job source (Requirement 3.1): an aggregator API, a public ATS board, or
 * an LLM web search. Every source is an independent implementation of this
 * interface and nothing downstream knows which one produced a row.
 *
 * The contract is deliberately narrow — take a query, return normalized jobs —
 * so adding a source is additive (a new class plus one entry in
 * `config/job_sources.php`) and requires no change to discovery, scoring,
 * tailoring or apply code (Requirement 3.2, design.md Property 1).
 *
 * Implementations MUST:
 *  - return a `Collection` of {@see NormalizedJob} only, never raw payloads;
 *  - set `sourceKey` on every job to the same value `key()` returns, so a row
 *    can always be traced back to the source that produced it;
 *  - treat `$query->limit` as a ceiling, returning fewer results when the
 *    source has fewer;
 *  - throw on transport/auth failure rather than returning a partial
 *    collection silently — the orchestrator (task 8.7) decides whether one
 *    dead source fails the run (design.md: it doesn't; it logs and skips).
 *
 * Implementations MUST NOT persist anything or dispatch jobs. Deduplication,
 * upserting into `job_listings` and rate-limit bookkeeping are the
 * orchestrator's business, not a provider's.
 */
interface JobSourceProvider
{
    /**
     * Stable machine identifier for this source, e.g. 'adzuna', 'jsearch',
     * 'greenhouse', 'llm_search'.
     *
     * This value is written to `job_listings.source_key` (task 8.6), used as
     * the `provider_usage.provider_key` counter name (task 8.8), and is the
     * key this provider is registered under in `config/job_sources.php`. It is
     * part of the data model: once rows exist for a key, don't rename it.
     */
    public function key(): string;

    /**
     * Run one search against the source.
     *
     * @return Collection<int, NormalizedJob>
     */
    public function search(JobSearchQuery $query): Collection;
}

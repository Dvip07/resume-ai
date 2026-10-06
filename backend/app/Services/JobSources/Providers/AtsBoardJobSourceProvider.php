<?php

namespace App\Services\JobSources\Providers;

use App\Services\JobSources\JobSearchQuery;
use App\Services\JobSources\JobSourceProvider;
use App\Services\JobSources\NormalizedJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Shared behaviour for the public ATS job-board sources (Requirement 3.1,
 * task 8.4, design.md "Job Source Providers"): Greenhouse's
 * `boards-api.greenhouse.io/v1/boards/{company}/jobs` and Lever's
 * `api.lever.co/v0/postings/{company}`.
 *
 * These are public, intentionally machine-readable endpoints — the same JSON the
 * companies' own careers pages consume — so there's no ToS conflict and no key
 * to hold. What makes them different from the aggregators (Adzuna, JSearch) is
 * worth stating, because it shapes this class:
 *
 *  1. **No query interface.** A board endpoint returns *the whole board*, every
 *     open role including sales and warehouse work. There is no `what` or
 *     `location` parameter to send. So the query is applied *client-side* here
 *     (see {@see matches()}) rather than by the source.
 *  2. **Targeted, not searched.** A board only exists for one company, so the
 *     user has to say which companies to poll. That's the "configurable per
 *     company slug" part of task 8.4: `services.{key}.companies`.
 *  3. **Full descriptions.** Unlike aggregator teasers, boards carry the
 *     complete JD, so these jobs usually skip the enrichment stage
 *     (Requirement 3.4) entirely — which is most of why they're worth having.
 *  4. **No salary.** Neither API exposes structured pay. Per NormalizedJob's
 *     contract, omitting the salary is the safe choice: the model router falls
 *     back to its complexity heuristic (Requirement 4.4) instead of misreading
 *     an unlabelled figure.
 *
 * design.md calls for "one implementation per ATS"; the per-ATS parts are the
 * URL shape and the field names, so those are the abstract methods below and
 * everything else (slug config, client-side filtering, the limit ceiling,
 * per-run dedupe, error handling) lives here once. Each concrete subclass is
 * registered under its own key in `config/job_sources.php` — `greenhouse`,
 * `lever` — because `job_listings.source_key` and the `provider_usage` counters
 * (task 8.8) need to distinguish them.
 *
 * One board being down or renamed must not lose the rest of the run, so a
 * per-board failure is logged and skipped. A subclass-wide transport failure
 * still surfaces only as skipped boards here rather than an exception, since
 * unlike a shared aggregator endpoint each board is an independent target and
 * "Acme deleted their board" is a normal, expected condition.
 */
abstract class AtsBoardJobSourceProvider implements JobSourceProvider
{
    /**
     * URL of one company's board feed.
     *
     * @param array{slug: string, company: string} $company
     */
    abstract protected function boardUrl(array $company): string;

    /**
     * Pull the list of postings out of one board's decoded response body.
     * Greenhouse wraps them in `jobs`, Lever returns a bare array.
     *
     * @return array<int, mixed>
     */
    abstract protected function extractPostings(mixed $payload): array;

    /** The board's own stable id for a posting, Requirement 3.3's exact dedupe key. */
    abstract protected function externalId(array $posting): ?string;

    abstract protected function title(array $posting): string;

    abstract protected function location(array $posting): string;

    abstract protected function isRemote(array $posting): bool;

    /**
     * The public posting URL. Must be absolute http(s) — enrichment (task 9)
     * and the apply adapters (task 15) both dereference it.
     *
     * @param array{slug: string, company: string} $company
     */
    abstract protected function applicationUrl(array $posting, array $company): string;

    /** Full JD as plain text, or null when the board omitted it. */
    abstract protected function description(array $posting): ?string;

    abstract protected function postedAt(array $posting): ?Carbon;

    /**
     * One request per configured board, stopping as soon as `$query->limit`
     * matching jobs have been collected. Boards are polled in configuration
     * order, so the user's priority companies should be listed first.
     *
     * Postings are de-duplicated by board id within the run; cross-run and
     * cross-source dedupe belong to the orchestrator (task 8.7).
     *
     * @return Collection<int, NormalizedJob>
     */
    public function search(JobSearchQuery $query): Collection
    {
        $jobs = collect();
        $seen = [];

        foreach ($this->companies() as $company) {
            if ($jobs->count() >= $query->limit) {
                break;
            }

            foreach ($this->fetchBoard($company) as $posting) {
                if (! is_array($posting)) {
                    continue;
                }

                $externalId = $this->externalId($posting);
                $seenKey = $company['slug'].':'.$externalId;

                if ($externalId !== null && isset($seen[$seenKey])) {
                    continue;
                }

                if (! $this->matches($posting, $query)) {
                    continue;
                }

                $job = $this->normalize($posting, $company);

                if ($job === null) {
                    continue;
                }

                if ($externalId !== null) {
                    $seen[$seenKey] = true;
                }

                $jobs->push($job);

                if ($jobs->count() >= $query->limit) {
                    return $jobs;
                }
            }
        }

        return $jobs;
    }

    /**
     * The raw postings for one board, or an empty array when that board can't
     * be read.
     *
     * A dead or renamed board is an ordinary condition (companies churn ATS
     * vendors), and every board is an independent target, so this logs and
     * returns nothing instead of throwing — the remaining boards still get
     * polled. That is a deliberate departure from the aggregator providers,
     * where a failure means the whole source is unavailable and the
     * JobSourceProvider contract wants an exception.
     *
     * @param array{slug: string, company: string} $company
     *
     * @return array<int, mixed>
     */
    protected function fetchBoard(array $company): array
    {
        try {
            $response = Http::timeout((int) $this->config('timeout', 15))
                ->acceptJson()
                ->get($this->boardUrl($company), $this->queryParameters())
                ->throw();

            return $this->extractPostings($response->json());
        } catch (Throwable $e) {
            Log::warning('Skipped an unreadable ATS board.', [
                'source' => $this->key(),
                'slug' => $company['slug'],
                'reason' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Query string for a board request. Boards take no search parameters; this
     * exists for the per-ATS response-shape flags (Greenhouse's
     * `content=true`, Lever's `mode=json`).
     *
     * @return array<string, mixed>
     */
    protected function queryParameters(): array
    {
        return [];
    }

    /**
     * Does this posting answer what the user asked for?
     *
     * Boards can't filter, so this is where the JobSearchQuery is honoured.
     * Title matching is on by default and is the important one — without it a
     * discovery run for "Laravel Developer" would happily return the company's
     * open recruiter and forklift-operator roles.
     *
     * A role matches when every whitespace-separated token in it appears
     * somewhere in the title, so "Laravel Developer" still matches "Senior
     * Laravel Developer, Platform". Any one role matching is enough.
     *
     * Location matching is off by default (`filter_by_location`): board
     * location strings are free text written by whoever posted the role
     * ("SF Bay Area (Hybrid)", "Remote — Americas"), so substring-matching a
     * user's "Toronto, Ontario" against them drops far more real matches than
     * it removes irrelevant ones. `remoteOnly` is honoured when the board says
     * anything about remoteness, since that signal is comparatively reliable.
     */
    protected function matches(array $posting, JobSearchQuery $query): bool
    {
        if ($query->remoteOnly && ! $this->isRemote($posting)) {
            return false;
        }

        if ((bool) $this->config('match_titles', true)
            && ! $this->matchesAnyRole($this->title($posting), $query->roles)) {
            return false;
        }

        if ($query->location !== null && (bool) $this->config('filter_by_location', false)) {
            $location = mb_strtolower($this->location($posting));

            // A remote role is available from the requested location by
            // definition, so it isn't filtered out on geography.
            if (! $this->isRemote($posting)
                && ! $this->locationMatches($location, $query->location)) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $roles */
    protected function matchesAnyRole(string $title, array $roles): bool
    {
        $title = mb_strtolower($title);

        foreach ($roles as $role) {
            $tokens = preg_split('/\s+/', mb_strtolower(trim($role)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            if ($tokens === []) {
                continue;
            }

            $matchedAll = true;

            foreach ($tokens as $token) {
                if (! str_contains($title, $token)) {
                    $matchedAll = false;
                    break;
                }
            }

            if ($matchedAll) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when any comma-separated part of the requested location appears in
     * the posting's location string, e.g. "Toronto, Ontario" matches
     * "Toronto, ON, Canada" on "toronto".
     */
    protected function locationMatches(string $postingLocation, string $requested): bool
    {
        foreach (explode(',', mb_strtolower($requested)) as $part) {
            $part = trim($part);

            if ($part !== '' && str_contains($postingLocation, $part)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Map one posting onto a NormalizedJob, or null when it's too incomplete to
     * use (no title, or no absolute apply URL). A single malformed posting
     * shouldn't fail the board.
     *
     * The company name comes from configuration, not the payload: neither
     * board's postings feed names the employer (it's implied by which board you
     * fetched), and `company` is a required NormalizedJob field that feeds the
     * dedupe hash (Requirement 3.3).
     *
     * @param array{slug: string, company: string} $company
     */
    protected function normalize(array $posting, array $company): ?NormalizedJob
    {
        try {
            return new NormalizedJob(
                sourceKey: $this->key(),
                title: $this->title($posting),
                company: $company['company'],
                applicationUrl: $this->applicationUrl($posting, $company),
                location: $this->location($posting),
                externalId: $this->externalId($posting),
                description: $this->description($posting),
                postedAt: $this->postedAt($posting),
                // Boards expose no structured pay; see the class docblock.
                salaryMin: null,
                salaryMax: null,
                currency: null,
            );
        } catch (Throwable $e) {
            Log::warning('Skipped an unusable ATS board posting.', [
                'source' => $this->key(),
                'slug' => $company['slug'],
                'external_id' => $this->externalId($posting),
                'reason' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * The boards to poll, normalized to `['slug' => ..., 'company' => ...]`.
     *
     * Accepts, in `services.{key}.companies`:
     *  - a comma-separated string, so it can come straight from `.env`:
     *    `GREENHOUSE_COMPANIES="acme,globex:Globex Inc"`
     *  - a list of slugs: `['acme', 'globex']`
     *  - a slug => display-name map: `['globex' => 'Globex Inc']`
     *  - a list of `['slug' => ..., 'company' => ...]` entries
     *
     * The optional display name exists because a slug is not a company name:
     * `globex-inc-1` would otherwise be written to `job_listings.company` and
     * shown to the user. Absent one, the slug is title-cased as a best effort.
     *
     * Duplicate slugs collapse (last display name wins) so a board is never
     * fetched twice in one run. An empty list means no requests at all — the
     * source is inert until the user names companies, which is why it can ship
     * enabled without doing anything unexpected.
     *
     * @return list<array{slug: string, company: string}>
     */
    protected function companies(): array
    {
        $configured = $this->config('companies', []);

        if (is_string($configured)) {
            $configured = explode(',', $configured);
        }

        if (! is_array($configured)) {
            return [];
        }

        $companies = [];

        foreach ($configured as $key => $value) {
            [$slug, $name] = $this->parseCompanyEntry($key, $value);

            if ($slug === null) {
                continue;
            }

            $companies[$slug] = [
                'slug' => $slug,
                'company' => $name ?? $this->companyNameFromSlug($slug),
            ];
        }

        return array_values($companies);
    }

    /**
     * @return array{0: ?string, 1: ?string} slug (null when unusable) and optional display name
     */
    private function parseCompanyEntry(int|string $key, mixed $value): array
    {
        // ['slug' => ..., 'company' => ...]
        if (is_array($value)) {
            $slug = $this->normalizeSlug($value['slug'] ?? $value[0] ?? null);
            $name = $value['company'] ?? $value['name'] ?? $value[1] ?? null;

            return [$slug, $this->cleanName($name)];
        }

        // 'globex' => 'Globex Inc'
        if (is_string($key)) {
            return [$this->normalizeSlug($key), $this->cleanName($value)];
        }

        if (! is_string($value)) {
            return [null, null];
        }

        // 'acme' or 'globex:Globex Inc' (the .env-friendly form)
        $parts = explode(':', $value, 2);

        return [
            $this->normalizeSlug($parts[0]),
            $this->cleanName($parts[1] ?? null),
        ];
    }

    private function normalizeSlug(mixed $slug): ?string
    {
        if (! is_string($slug)) {
            return null;
        }

        $slug = trim($slug);

        // Both APIs put the slug in the URL path, so refuse anything that could
        // traverse or otherwise reshape it rather than building a broken URL.
        return preg_match('/^[A-Za-z0-9._-]+$/', $slug) ? $slug : null;
    }

    private function cleanName(mixed $name): ?string
    {
        if (! is_string($name)) {
            return null;
        }

        $name = trim($name);

        return $name === '' ? null : $name;
    }

    /** 'globex-inc' → 'Globex Inc'. Crude, but better than a raw slug in the UI. */
    private function companyNameFromSlug(string $slug): string
    {
        return ucwords(trim(preg_replace('/[\s_-]+/', ' ', $slug)));
    }

    /**
     * HTML (often entity-escaped, as Greenhouse serves it) reduced to readable
     * plain text: entities decoded, block boundaries turned into newlines, tags
     * dropped, runs of blank space collapsed. Scoring and tailoring read this
     * as text, and leaving markup in it wastes prompt tokens.
     */
    protected function plainText(mixed $html): ?string
    {
        if (! is_string($html)) {
            return null;
        }

        // Greenhouse escapes its already-escaped content (`&amp;#39;` for an
        // apostrophe inside a `&lt;p&gt;`), so decode until the string settles
        // rather than a fixed number of passes — one pass leaves literal tags,
        // two can still leave stray entities in the text. Bounded so a
        // pathological input can't spin.
        $text = (string) $html;

        for ($pass = 0; $pass < 4; $pass++) {
            $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if ($decoded === $text) {
                break;
            }

            $text = $decoded;
        }

        $text = preg_replace('#<br\s*/?>#i', "\n", $text);
        $text = preg_replace('#</(p|div|li|ul|ol|h[1-6]|tr|section)>#i', "\n", $text);
        $text = preg_replace('#<li[^>]*>#i', '- ', $text);
        $text = strip_tags($text);

        // Non-breaking spaces survive decoding as U+00A0 and read as garbage.
        $text = str_replace("\u{a0}", ' ', $text);
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\s*\n\s*/', "\n", $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = trim($text);

        return $text === '' ? null : $text;
    }

    /**
     * Settings live under `services.{key}` alongside the other third-party
     * configuration, matching the Adzuna/JSearch providers.
     */
    protected function config(string $key, mixed $default = null): mixed
    {
        return config("services.{$this->key()}.{$key}", $default);
    }
}

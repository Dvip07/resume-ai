<?php

namespace App\Services\JobSources;

/**
 * The single implementation of Requirement 3.3's composite dedupe key:
 * a hash of normalized `title + company + location`.
 *
 * It lives in its own class rather than only on {@see NormalizedJob} because
 * both sides of the comparison need it and they arrive differently: incoming
 * provider results are `NormalizedJob`s, while the rows already in
 * `job_listings` are Eloquent models (the `dedupe_hash` column added in task
 * 8.6, backfilled with this same function). If the two sides ever normalized
 * differently, dedupe would silently stop working — so there is exactly one
 * place the rules live.
 *
 * ## Normalization rules, in order
 *
 * Applied independently to each of the three parts:
 *
 *  1. **Case** — lowercased with `mb_strtolower`, so "Senior Engineer" and
 *     "senior engineer" collide.
 *  2. **Separators and punctuation** — every character that isn't a Unicode
 *     letter, digit or whitespace becomes a single space. "Senior/Staff",
 *     "Senior - Staff" and "Senior (Staff)" all reduce to "senior staff".
 *     Replacing with a space rather than deleting keeps "front-end" from
 *     becoming "frontend" while "front end" stays "front end" — the two
 *     spellings collide, which is what we want.
 *  3. **Whitespace** — any run of whitespace (including non-breaking spaces
 *     and newlines from scraped HTML) collapses to one space, then trimmed.
 *  4. **Company legal suffixes** — trailing corporate forms are dropped from
 *     the *company* part only, so "Acme", "Acme Inc." and "Acme, LLC" collide.
 *     Only trailing tokens are stripped, and never the last remaining token,
 *     so a company literally named "Limited" survives. See
 *     {@see self::COMPANY_SUFFIXES}.
 *
 * The three normalized parts are then joined with `|` (a character step 2 has
 * already removed from the parts, so the delimiter can't be forged by input)
 * and hashed with SHA-256. Hex output is stable, fixed width, and indexable as
 * a plain string column.
 *
 * ## Deliberate non-goals
 *
 * - **No accent folding.** "Zürich" and "Zurich" hash differently. Folding
 *   correctly needs a transliteration table (or intl) and the failure mode is
 *   only a duplicate row, which is cheaper than wrongly merging two postings.
 * - **No location alias resolution.** "London, UK" and "London, United
 *   Kingdom" hash differently, as do "Remote" and "Remote - US". A geocoding
 *   pass would fix this and is out of scope here; this is why Requirement 3.3
 *   allows the source-provided external id as an alternative key, which the
 *   orchestrator (task 8.7) checks first when a source supplies one.
 * - **No seniority or title synonym matching.** "Sr. Engineer" normalizes to
 *   "sr engineer", not "senior engineer".
 *
 * Because the hash is persisted, changing any rule above is a data migration:
 * every existing `job_listings.dedupe_hash` has to be recomputed or dedupe
 * will miss everything ingested before the change.
 */
class JobDedupeHasher
{
    /**
     * Trailing corporate-form tokens stripped from a company name. Kept as a
     * closed, code-level list rather than config: it's part of the persisted
     * hash definition, so it must not be tunable per environment.
     */
    public const COMPANY_SUFFIXES = [
        'inc', 'incorporated', 'llc', 'llp', 'lp', 'ltd', 'limited',
        'corp', 'corporation', 'co', 'company', 'plc', 'gmbh', 'ag',
        'sa', 'sas', 'srl', 'bv', 'nv', 'ab', 'as', 'oy', 'pty',
        'group', 'holdings',
    ];

    /**
     * The stored hash for a title/company/location triple.
     *
     * Empty or whitespace-only parts are allowed and normalize to an empty
     * string — a job with no location still gets a stable hash rather than an
     * exception, since some sources (LLM search especially) omit it.
     */
    public function hash(string $title, string $company, string $location): string
    {
        return hash('sha256', $this->fingerprint($title, $company, $location));
    }

    /**
     * The normalized, human-readable string the hash is taken of. Exposed
     * because it's what you want in a log line or a test failure message when
     * two records that should have matched didn't.
     */
    public function fingerprint(string $title, string $company, string $location): string
    {
        return implode('|', [
            $this->normalize($title),
            $this->normalizeCompany($company),
            $this->normalize($location),
        ]);
    }

    /** Rules 1-3, applied to any part. */
    public function normalize(string $value): string
    {
        $value = mb_strtolower($value);
        // Anything that isn't a letter, number or whitespace becomes a space.
        $value = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';

        return trim($value);
    }

    /** Rules 1-3 plus rule 4 (trailing legal suffixes), for company names. */
    public function normalizeCompany(string $company): string
    {
        $tokens = array_filter(explode(' ', $this->normalize($company)), fn ($t) => $t !== '');
        $tokens = array_values($tokens);

        // Strip repeatedly so "Acme Holdings Ltd" reduces to "acme", but never
        // strip the only token left: a company named "Limited" keeps its name.
        while (count($tokens) > 1 && in_array(end($tokens), self::COMPANY_SUFFIXES, true)) {
            array_pop($tokens);
        }

        return implode(' ', $tokens);
    }
}

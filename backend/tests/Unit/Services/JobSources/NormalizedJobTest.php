<?php

namespace Tests\Unit\Services\JobSources;

use App\Services\JobSources\JobDedupeHasher;
use App\Services\JobSources\NormalizedJob;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The shared DTO every provider must produce (Requirements 3.1, 3.2): field
 * normalization, the salary/currency contract, and the dedupe hash it exposes
 * for Requirement 3.3.
 */
class NormalizedJobTest extends TestCase
{
    private function job(array $overrides = []): NormalizedJob
    {
        return new NormalizedJob(...array_merge([
            'sourceKey' => 'adzuna',
            'title' => 'Senior Engineer',
            'company' => 'Acme',
            'applicationUrl' => 'https://example.test/jobs/1',
        ], $overrides));
    }

    public function test_it_trims_fields_and_defaults_optional_ones(): void
    {
        $job = $this->job([
            'sourceKey' => ' adzuna ',
            'title' => "  Senior Engineer\n",
            'company' => ' Acme ',
            'location' => '  Toronto, ON ',
            'externalId' => ' abc123 ',
        ]);

        $this->assertSame('adzuna', $job->sourceKey);
        $this->assertSame('Senior Engineer', $job->title);
        $this->assertSame('Acme', $job->company);
        $this->assertSame('Toronto, ON', $job->location);
        $this->assertSame('abc123', $job->externalId);
        $this->assertNull($job->description);
        $this->assertNull($job->postedAt);
        $this->assertFalse($job->hasSalary());
    }

    /** Location is optional: LLM search and some ATS boards don't supply one. */
    public function test_location_may_be_unknown(): void
    {
        $this->assertSame('', $this->job()->location);
    }

    /** Blank optional strings normalize to null rather than ''. */
    public function test_blank_external_id_and_description_become_null(): void
    {
        $job = $this->job(['externalId' => '  ', 'description' => "  \n "]);

        $this->assertNull($job->externalId);
        $this->assertNull($job->description);
    }

    public function test_it_keeps_the_posted_at_instant(): void
    {
        $postedAt = Carbon::parse('2025-01-15 09:30:00');

        $this->assertTrue($postedAt->equalTo($this->job(['postedAt' => $postedAt])->postedAt));
    }

    /**
     * @dataProvider blankRequiredFields
     */
    public function test_it_rejects_blank_required_fields(string $field): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($field);

        $this->job([$field => '   ']);
    }

    public static function blankRequiredFields(): array
    {
        return [['sourceKey'], ['title'], ['company']];
    }

    /**
     * @dataProvider badUrls
     */
    public function test_it_requires_an_absolute_http_url(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('applicationUrl');

        $this->job(['applicationUrl' => $url]);
    }

    public static function badUrls(): array
    {
        return [
            'blank' => [' '],
            'relative' => ['/jobs/1'],
            'no scheme' => ['example.test/jobs/1'],
            'wrong scheme' => ['ftp://example.test/jobs/1'],
            'javascript' => ['javascript:alert(1)'],
        ];
    }

    public function test_it_accepts_http_and_https(): void
    {
        $this->assertSame(
            'http://example.test/jobs/1',
            $this->job(['applicationUrl' => ' http://example.test/jobs/1 '])->applicationUrl
        );
        $this->assertSame(
            'https://example.test/jobs/1',
            $this->job(['applicationUrl' => 'https://example.test/jobs/1'])->applicationUrl
        );
    }

    public function test_currency_is_uppercased_when_a_salary_is_present(): void
    {
        $job = $this->job(['salaryMin' => 80000, 'salaryMax' => 120000, 'currency' => 'usd']);

        $this->assertTrue($job->hasSalary());
        $this->assertSame('USD', $job->currency);
        $this->assertSame(80000, $job->salaryMin);
        $this->assertSame(120000, $job->salaryMax);
    }

    /**
     * Task 7.3's gap: thresholds do no FX conversion, so an unlabelled figure
     * would be misread. The DTO refuses to carry one.
     */
    public function test_salary_without_currency_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('currency');

        $this->job(['salaryMax' => 120000]);
    }

    public function test_currency_must_be_an_iso_code(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->job(['salaryMin' => 100, 'currency' => 'dollars']);
    }

    /** A currency with no salary carries no information, so it's dropped. */
    public function test_currency_without_salary_is_discarded(): void
    {
        $this->assertNull($this->job(['currency' => 'USD'])->currency);
    }

    public function test_negative_and_inverted_salaries_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->job(['salaryMin' => -1, 'currency' => 'USD']);
    }

    public function test_inverted_salary_bounds_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('salaryMin');

        $this->job(['salaryMin' => 200000, 'salaryMax' => 100000, 'currency' => 'USD']);
    }

    /** One bound alone is fine — many sources publish only a maximum. */
    public function test_a_single_salary_bound_is_allowed(): void
    {
        $job = $this->job(['salaryMax' => 120000, 'currency' => 'CAD']);

        $this->assertNull($job->salaryMin);
        $this->assertSame(120000, $job->salaryMax);
    }

    /** The DTO's hash must be the hasher's hash — one rule set, not two. */
    public function test_dedupe_hash_matches_the_shared_hasher(): void
    {
        $job = $this->job(['location' => 'Toronto, ON']);
        $hasher = new JobDedupeHasher();

        $this->assertSame(
            $hasher->hash('Senior Engineer', 'Acme', 'Toronto, ON'),
            $job->dedupeHash()
        );
        $this->assertSame(
            $hasher->fingerprint('Senior Engineer', 'Acme', 'Toronto, ON'),
            $job->dedupeFingerprint()
        );
    }

    /** Requirement 3.3: the same posting from two sources dedupes. */
    public function test_same_posting_from_different_sources_shares_a_dedupe_hash(): void
    {
        $adzuna = $this->job([
            'sourceKey' => 'adzuna',
            'title' => 'Senior/Staff Engineer',
            'company' => 'Acme Inc.',
            'location' => 'Toronto, ON',
            'externalId' => '999',
            'applicationUrl' => 'https://adzuna.test/j/1',
        ]);

        $jsearch = $this->job([
            'sourceKey' => 'jsearch',
            'title' => 'senior staff engineer',
            'company' => 'ACME',
            'location' => 'toronto ON',
            'applicationUrl' => 'https://jsearch.test/other/2',
        ]);

        $this->assertSame($adzuna->dedupeHash(), $jsearch->dedupeHash());
    }

    /** The hash covers title/company/location only — nothing else moves it. */
    public function test_dedupe_hash_ignores_non_identity_fields(): void
    {
        $this->assertSame(
            $this->job(['location' => 'Berlin'])->dedupeHash(),
            $this->job([
                'location' => 'Berlin',
                'sourceKey' => 'greenhouse',
                'externalId' => 'x',
                'description' => 'a much longer description',
                'applicationUrl' => 'https://other.test/j/9',
                'postedAt' => Carbon::parse('2020-01-01'),
                'salaryMin' => 10,
                'currency' => 'EUR',
            ])->dedupeHash(),
        );
    }

    public function test_needs_enrichment_tracks_the_configured_minimum(): void
    {
        config(['job_sources.min_description_length' => 10]);

        $this->assertTrue($this->job()->needsEnrichment(), 'null description');
        $this->assertTrue($this->job(['description' => 'short'])->needsEnrichment());
        $this->assertFalse($this->job(['description' => str_repeat('a', 10)])->needsEnrichment());
    }
}

<?php

namespace Tests\Unit\Services\JobSources;

use App\Services\JobSources\JobDedupeHasher;
use Tests\TestCase;

/**
 * Requirement 3.3's composite dedupe key: normalized title + company +
 * location. These tests pin the documented normalization rules, because the
 * hash is persisted (`job_listings.dedupe_hash`, task 8.6) and any silent
 * change to the rules stops dedupe working for every existing row.
 */
class JobDedupeHasherTest extends TestCase
{
    private JobDedupeHasher $hasher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hasher = new JobDedupeHasher();
    }

    public function test_hash_is_stable_and_hex_sha256(): void
    {
        $hash = $this->hasher->hash('Senior Engineer', 'Acme', 'Toronto, ON');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
        $this->assertSame($hash, $this->hasher->hash('Senior Engineer', 'Acme', 'Toronto, ON'));
    }

    /** Rule 1: case is ignored. */
    public function test_case_differences_collide(): void
    {
        $this->assertSame(
            $this->hasher->hash('Senior Engineer', 'Acme', 'Toronto'),
            $this->hasher->hash('SENIOR engineer', 'ACME', 'toronto'),
        );
    }

    /** Rules 2 and 3: punctuation becomes a space, whitespace collapses. */
    public function test_punctuation_and_whitespace_differences_collide(): void
    {
        $expected = $this->hasher->hash('Senior Staff Engineer', 'Acme', 'Toronto ON');

        $this->assertSame($expected, $this->hasher->hash('Senior/Staff Engineer', 'Acme', 'Toronto, ON'));
        $this->assertSame($expected, $this->hasher->hash('Senior - Staff  Engineer', 'Acme', 'Toronto ON'));
        $this->assertSame($expected, $this->hasher->hash("  Senior (Staff)\nEngineer ", 'Acme!', 'Toronto  ON'));
    }

    /** Rule 2 replaces rather than deletes, so hyphenated and spaced spellings agree. */
    public function test_hyphenated_and_spaced_words_collide_but_do_not_concatenate(): void
    {
        $this->assertSame('front end developer', $this->hasher->normalize('Front-End Developer'));
        $this->assertSame('front end developer', $this->hasher->normalize('front end developer'));
        $this->assertNotSame('frontend developer', $this->hasher->normalize('Front-End Developer'));
    }

    /** Rule 4: trailing legal suffixes are dropped from the company only. */
    public function test_company_legal_suffixes_are_stripped(): void
    {
        $expected = $this->hasher->hash('Engineer', 'Acme', 'Berlin');

        foreach (['Acme Inc.', 'Acme, LLC', 'ACME Ltd', 'Acme GmbH', 'Acme Holdings Ltd'] as $company) {
            $this->assertSame($expected, $this->hasher->hash('Engineer', $company, 'Berlin'), $company);
        }
    }

    public function test_suffix_stripping_never_empties_the_company(): void
    {
        $this->assertSame('limited', $this->hasher->normalizeCompany('Limited'));
        $this->assertSame('group', $this->hasher->normalizeCompany('Group'));
    }

    /** Suffix stripping is company-specific: a title keeping "co" must not lose it. */
    public function test_suffixes_are_not_stripped_from_titles_or_locations(): void
    {
        $this->assertSame('engineer co', $this->hasher->normalize('Engineer Co'));
        $this->assertNotSame(
            $this->hasher->hash('Engineer Co', 'Acme', 'Berlin'),
            $this->hasher->hash('Engineer', 'Acme', 'Berlin'),
        );
    }

    /** Genuinely different postings must not collide. */
    public function test_different_jobs_hash_differently(): void
    {
        $base = $this->hasher->hash('Senior Engineer', 'Acme', 'Toronto');

        $this->assertNotSame($base, $this->hasher->hash('Junior Engineer', 'Acme', 'Toronto'));
        $this->assertNotSame($base, $this->hasher->hash('Senior Engineer', 'Globex', 'Toronto'));
        $this->assertNotSame($base, $this->hasher->hash('Senior Engineer', 'Acme', 'Vancouver'));
    }

    /**
     * The `|` delimiter can't be forged: it's stripped from the parts by rule 2,
     * so shifting text across a boundary changes the hash.
     */
    public function test_field_boundaries_cannot_be_forged(): void
    {
        $this->assertNotSame(
            $this->hasher->hash('Engineer', 'Acme', 'Berlin'),
            $this->hasher->hash('Engineer|Acme', '', 'Berlin'),
        );
    }

    /** Missing parts are allowed — some sources omit the location entirely. */
    public function test_blank_parts_are_allowed_and_stable(): void
    {
        $this->assertSame(
            $this->hasher->hash('Engineer', 'Acme', ''),
            $this->hasher->hash('Engineer', 'Acme', '   '),
        );
        $this->assertSame('engineer|acme|', $this->hasher->fingerprint('Engineer', 'Acme', ''));
    }

    /** Documented non-goals: folding these is explicitly out of scope. */
    public function test_documented_non_goals_do_not_collide(): void
    {
        // No accent folding.
        $this->assertNotSame(
            $this->hasher->hash('Engineer', 'Acme', 'Zurich'),
            $this->hasher->hash('Engineer', 'Acme', 'Zürich'),
        );
        // No location alias resolution.
        $this->assertNotSame(
            $this->hasher->hash('Engineer', 'Acme', 'London, UK'),
            $this->hasher->hash('Engineer', 'Acme', 'London, United Kingdom'),
        );
        // No title synonym matching.
        $this->assertNotSame(
            $this->hasher->hash('Sr. Engineer', 'Acme', 'Berlin'),
            $this->hasher->hash('Senior Engineer', 'Acme', 'Berlin'),
        );
    }
}

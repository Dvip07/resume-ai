<?php

namespace Tests\Unit\Services\JobSources;

use App\Services\JobSources\JobSearchQuery;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The value object every provider receives (Requirement 3.1). design.md names
 * the type without defining it, so these tests pin the definition: what a
 * discovery run asks for, and what it refuses to ask for.
 */
class JobSearchQueryTest extends TestCase
{
    public function test_it_normalizes_roles_and_location(): void
    {
        $query = new JobSearchQuery(
            roles: ['  Backend Engineer ', '', 'Platform Engineer', '   '],
            userId: 7,
            location: '  Toronto, ON  ',
        );

        $this->assertSame(['Backend Engineer', 'Platform Engineer'], $query->roles);
        $this->assertSame('Backend Engineer', $query->primaryRole());
        $this->assertSame('Backend Engineer Platform Engineer', $query->keywordString());
        $this->assertSame('Backend Engineer, Platform Engineer', $query->keywordString(', '));
        $this->assertSame('Toronto, ON', $query->location);
        $this->assertSame(7, $query->userId);
        $this->assertFalse($query->remoteOnly);
    }

    public function test_a_single_role_may_be_passed_as_a_string(): void
    {
        $this->assertSame(['Data Engineer'], (new JobSearchQuery('Data Engineer', 1))->roles);
    }

    /** "Anywhere" is null, not an empty string providers have to special-case. */
    public function test_blank_location_becomes_null(): void
    {
        $this->assertNull((new JobSearchQuery('Engineer', 1, location: '   '))->location);
        $this->assertNull((new JobSearchQuery('Engineer', 1))->location);
    }

    public function test_it_rejects_a_query_with_no_usable_role(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new JobSearchQuery(['  ', ''], 1);
    }

    public function test_limit_falls_back_to_config(): void
    {
        config(['job_sources.default_limit' => 11]);

        $this->assertSame(11, (new JobSearchQuery('Engineer', 1))->limit);
        $this->assertSame(5, (new JobSearchQuery('Engineer', 1, limit: 5))->limit);
    }

    public function test_it_rejects_a_non_positive_limit(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new JobSearchQuery('Engineer', 1, limit: 0);
    }

    public function test_with_limit_and_with_roles_return_modified_copies(): void
    {
        $query = new JobSearchQuery(
            roles: ['Engineer', 'Architect'],
            userId: 3,
            location: 'Berlin',
            remoteOnly: true,
            limit: 20,
        );

        $narrowed = $query->withLimit(5);
        $singleRole = $query->withRoles('Architect');

        $this->assertSame(5, $narrowed->limit);
        $this->assertSame(20, $query->limit, 'original must be untouched');
        $this->assertSame(['Engineer', 'Architect'], $narrowed->roles);
        $this->assertTrue($narrowed->remoteOnly);
        $this->assertSame('Berlin', $narrowed->location);

        $this->assertSame(['Architect'], $singleRole->roles);
        $this->assertSame(20, $singleRole->limit);
        $this->assertSame(3, $singleRole->userId);
    }
}

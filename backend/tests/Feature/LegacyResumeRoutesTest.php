<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The Blade-era resume flow (App\Http\Controllers\ResumeController plus
 * resources/views/resumes) was removed once the `frontend/` resume screens
 * went live against Api\ResumeController.
 *
 * These assertions pin the cutover: the legacy URLs must be gone, not merely
 * unlinked, so a stale bookmark fails loudly instead of silently working
 * against the old implementation.
 *
 * Validates: Requirements 1.7, 12.2
 */
class LegacyResumeRoutesTest extends TestCase
{
    public function test_legacy_resume_index_route_is_removed(): void
    {
        $this->get('/resumes')->assertNotFound();
    }

    public function test_legacy_resume_create_route_is_removed(): void
    {
        $this->get('/resumes/create')->assertNotFound();
    }

    public function test_legacy_resume_store_route_is_removed(): void
    {
        $this->post('/resumes')->assertNotFound();
    }

    public function test_resumes_named_routes_no_longer_exist(): void
    {
        $this->assertFalse(app('router')->has('resumes.index'));
        $this->assertFalse(app('router')->has('resumes.create'));
        $this->assertFalse(app('router')->has('resumes.store'));
    }
}

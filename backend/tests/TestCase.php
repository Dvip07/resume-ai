<?php

namespace Tests;

use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Every simulated HTTP request in a feature test shares the same
        // long-lived application/auth-manager instance. Laravel's RequestGuard
        // (used by the sanctum guard) caches the first user it resolves for the
        // whole test process, so a follow-up request never re-validates its
        // bearer token — a token revoked mid-test would still appear valid.
        // In production each request is an isolated process, so we reset the
        // resolved guards after every handled request to mirror that isolation.
        $this->app['events']->listen(RequestHandled::class, function () {
            $this->app['auth']->forgetGuards();
        });
    }
}

<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests locking in the CORS policy for the `api/*` routes
 * (config/cors.php), so the explicit allow-list can't silently regress into a
 * wildcard or start accepting arbitrary origins.
 *
 * Validates: Requirements 1.9
 */
class CorsTest extends TestCase
{
    use RefreshDatabase;

    private const ALLOWED_ORIGIN = 'http://localhost:5173';

    private const UNLISTED_ORIGIN = 'http://evil.example.com';

    /**
     * Force the two-entry, production-shaped allow-list (a distinct frontend
     * origin alongside the local Vite dev server).
     *
     * This matters because fruitcake/php-cors short-circuits when exactly one
     * origin is configured: it then emits that origin unconditionally instead
     * of comparing against the request's `Origin`. The browser still blocks a
     * mismatch, but only the multi-origin path omits the header outright, so
     * the strict assertions below are written against that shape.
     */
    private function useMultiOriginConfig(): void
    {
        config([
            'cors.allowed_origins' => [self::ALLOWED_ORIGIN, 'https://app.example.com'],
            'cors.allowed_origins_patterns' => [],
            'cors.supports_credentials' => true,
        ]);
    }

    public function test_preflight_from_an_allowed_origin_is_permitted_with_credentials(): void
    {
        $this->useMultiOriginConfig();

        $response = $this->call('OPTIONS', '/api/auth/login', [], [], [], [
            'HTTP_ORIGIN' => self::ALLOWED_ORIGIN,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type,x-requested-with',
        ]);

        $response->assertNoContent();
        $response->assertHeader('Access-Control-Allow-Origin', self::ALLOWED_ORIGIN);
        $response->assertHeader('Access-Control-Allow-Credentials', 'true');
    }

    public function test_actual_request_from_an_allowed_origin_echoes_that_origin(): void
    {
        $this->useMultiOriginConfig();

        $response = $this->postJson('/api/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'wrong-password',
        ], ['Origin' => self::ALLOWED_ORIGIN]);

        // The credentials are irrelevant here; only the CORS headers are.
        $response->assertHeader('Access-Control-Allow-Origin', self::ALLOWED_ORIGIN);
        $response->assertHeader('Access-Control-Allow-Credentials', 'true');
    }

    public function test_preflight_from_an_unlisted_origin_gets_no_allow_origin_header(): void
    {
        $this->useMultiOriginConfig();

        $response = $this->call('OPTIONS', '/api/auth/login', [], [], [], [
            'HTTP_ORIGIN' => self::UNLISTED_ORIGIN,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);

        $response->assertHeaderMissing('Access-Control-Allow-Origin');
        $response->assertHeaderMissing('Access-Control-Allow-Credentials');
    }

    public function test_actual_request_from_an_unlisted_origin_gets_no_allow_origin_header(): void
    {
        $this->useMultiOriginConfig();

        $response = $this->postJson('/api/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'wrong-password',
        ], ['Origin' => self::UNLISTED_ORIGIN]);

        $response->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    /**
     * Whatever the configured shape, an unlisted origin must never be told it
     * is allowed — no wildcard, and never its own origin echoed back.
     */
    public function test_unlisted_origin_is_never_granted_access_under_the_shipped_config(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'wrong-password',
        ], ['Origin' => self::UNLISTED_ORIGIN]);

        $allowOrigin = $response->headers->get('Access-Control-Allow-Origin');

        $this->assertNotSame('*', $allowOrigin);
        $this->assertNotSame(self::UNLISTED_ORIGIN, $allowOrigin);
    }

    public function test_shipped_config_never_allows_a_wildcard_origin(): void
    {
        $allowedOrigins = config('cors.allowed_origins');

        $this->assertNotEmpty($allowedOrigins);
        $this->assertNotContains('*', $allowedOrigins);
        // A wildcard origin is invalid alongside credentials, which the
        // httpOnly auth cookie flow requires.
        $this->assertTrue(config('cors.supports_credentials'));

        $response = $this->call('OPTIONS', '/api/auth/login', [], [], [], [
            'HTTP_ORIGIN' => self::ALLOWED_ORIGIN,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);

        $this->assertNotSame('*', $response->headers->get('Access-Control-Allow-Origin'));
    }
}

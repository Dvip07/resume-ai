<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Support\AuthTokenCookie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for the Sanctum bearer-token auth API
 * (App\Http\Controllers\Api\AuthController / routes/api.php).
 *
 * Validates: Requirements 1.5, 1.6
 */
class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_creates_a_user_and_returns_a_token(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['user', 'token'])
            ->assertJsonPath('user.email', 'jane@example.com');

        $this->assertNotEmpty($response->json('token'));

        $this->assertDatabaseHas('users', [
            'email' => 'jane@example.com',
            'name' => 'Jane Doe',
        ]);
    }

    public function test_login_with_correct_credentials_returns_user_and_token(): void
    {
        $user = User::factory()->create([
            'email' => 'login-success@example.com',
            'password' => bcrypt('correct-password'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['user', 'token'])
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.email', $user->email);

        $token = $response->json('token');
        $this->assertIsString($token);
        $this->assertNotEmpty($token);
    }

    public function test_login_with_wrong_password_is_rejected_and_issues_no_token(): void
    {
        $user = User::factory()->create([
            'email' => 'login-wrong-password@example.com',
            'password' => bcrypt('correct-password'),
        ]);

        $tokenCountBefore = $user->tokens()->count();

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'totally-wrong-password',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        $this->assertSame($tokenCountBefore, $user->tokens()->count());
    }

    public function test_login_with_unknown_email_is_rejected_and_issues_no_token(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'no-such-user@example.com',
            'password' => 'whatever-password',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_me_without_authorization_header_returns_401(): void
    {
        $response = $this->getJson('/api/me');

        $response->assertStatus(401);
    }

    public function test_me_with_garbage_bearer_token_returns_401(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer garbage-invalid-token')
            ->getJson('/api/me');

        $response->assertStatus(401);
    }

    public function test_me_with_valid_token_returns_the_authenticated_user(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/me');

        $response->assertStatus(200)
            ->assertJsonPath('id', $user->id)
            ->assertJsonPath('email', $user->email);
    }

    public function test_logout_revokes_the_current_token_and_subsequent_me_call_returns_401(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $logoutResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/auth/logout');

        $logoutResponse->assertStatus(200);

        $this->assertSame(0, $user->tokens()->count());

        $meResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/me');

        $meResponse->assertStatus(401);
    }

    public function test_logout_only_revokes_the_token_used_not_other_tokens_for_the_same_user(): void
    {
        $user = User::factory()->create();
        $tokenToRevoke = $user->createToken('token-a')->plainTextToken;
        $otherToken = $user->createToken('token-b')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$tokenToRevoke)
            ->postJson('/api/auth/logout')
            ->assertStatus(200);

        $this->assertSame(1, $user->tokens()->count());

        $this->withHeader('Authorization', 'Bearer '.$otherToken)
            ->getJson('/api/me')
            ->assertStatus(200)
            ->assertJsonPath('id', $user->id);
    }

    public function test_cors_allowed_origins_does_not_contain_a_wildcard(): void
    {
        $allowedOrigins = config('cors.allowed_origins');

        $this->assertIsArray($allowedOrigins);
        $this->assertNotContains('*', $allowedOrigins);
    }

    public function test_cors_supports_credentials_so_the_auth_cookie_is_sent_cross_origin(): void
    {
        $this->assertTrue(config('cors.supports_credentials'));
        $this->assertNotContains('*', config('cors.allowed_origins'));
    }

    /*
    |--------------------------------------------------------------------------
    | httpOnly auth-cookie flow (open decision #6)
    |--------------------------------------------------------------------------
    */

    public function test_login_sets_the_token_in_an_httponly_cookie(): void
    {
        $user = User::factory()->create([
            'email' => 'cookie-login@example.com',
            'password' => bcrypt('correct-password'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ]);

        $response->assertStatus(200)->assertCookie(AuthTokenCookie::NAME);

        $cookie = $response->getCookie(AuthTokenCookie::NAME, false);

        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame($response->json('token'), $cookie->getValue());
        $this->assertGreaterThan(time(), $cookie->getExpiresTime());
    }

    public function test_register_sets_the_token_in_an_httponly_cookie(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Cookie User',
            'email' => 'cookie-register@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(201)->assertCookie(AuthTokenCookie::NAME);

        $cookie = $response->getCookie(AuthTokenCookie::NAME, false);

        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame($response->json('token'), $cookie->getValue());
    }

    public function test_request_authenticated_with_only_the_cookie_succeeds(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('cookie-token')->plainTextToken;

        $response = $this->withCredentials()->withUnencryptedCookie(AuthTokenCookie::NAME, $token)
            ->withHeader(AuthTokenCookie::REQUIRED_HEADER, AuthTokenCookie::REQUIRED_HEADER_VALUE)
            ->getJson('/api/me');

        $response->assertStatus(200)
            ->assertJsonPath('id', $user->id)
            ->assertJsonPath('email', $user->email);
    }

    public function test_cookie_is_ignored_without_the_requested_with_header(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('cookie-token')->plainTextToken;

        $this->withCredentials()->withUnencryptedCookie(AuthTokenCookie::NAME, $token)
            ->getJson('/api/me')
            ->assertStatus(401);
    }

    public function test_an_explicit_authorization_header_wins_over_the_cookie(): void
    {
        $headerUser = User::factory()->create();
        $cookieUser = User::factory()->create();

        $headerToken = $headerUser->createToken('header-token')->plainTextToken;
        $cookieToken = $cookieUser->createToken('cookie-token')->plainTextToken;

        $this->withCredentials()->withUnencryptedCookie(AuthTokenCookie::NAME, $cookieToken)
            ->withHeader('Authorization', 'Bearer '.$headerToken)
            ->getJson('/api/me')
            ->assertStatus(200)
            ->assertJsonPath('id', $headerUser->id);
    }

    public function test_logout_clears_the_cookie_and_revokes_the_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('cookie-token')->plainTextToken;

        $response = $this->withCredentials()->withUnencryptedCookie(AuthTokenCookie::NAME, $token)
            ->withHeader(AuthTokenCookie::REQUIRED_HEADER, AuthTokenCookie::REQUIRED_HEADER_VALUE)
            ->postJson('/api/auth/logout');

        $response->assertStatus(200)->assertCookieExpired(AuthTokenCookie::NAME);

        $this->assertSame(0, $user->tokens()->count());

        $this->withCredentials()->withUnencryptedCookie(AuthTokenCookie::NAME, $token)
            ->withHeader(AuthTokenCookie::REQUIRED_HEADER, AuthTokenCookie::REQUIRED_HEADER_VALUE)
            ->getJson('/api/me')
            ->assertStatus(401);
    }

    public function test_request_without_cookie_or_header_returns_401(): void
    {
        $this->getJson('/api/me')->assertStatus(401);
    }
}

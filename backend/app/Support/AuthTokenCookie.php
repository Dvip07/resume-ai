<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\Cookie;

/**
 * Builds the httpOnly cookie that carries a Sanctum personal access token to
 * the browser (open decision #6 — see config/auth_cookie.php for the full
 * rationale, including why the cookie is deliberately not encrypted).
 */
class AuthTokenCookie
{
    /**
     * Cookie name. Kept as a constant (not env-driven) so bootstrap/app.php
     * can reference it while configuring middleware, where `config()` and
     * `env()` are not reliably available under a cached config.
     */
    public const NAME = 'auth_token';

    /** The custom header a cookie-authenticated request must carry. */
    public const REQUIRED_HEADER = 'X-Requested-With';

    public const REQUIRED_HEADER_VALUE = 'XMLHttpRequest';

    public static function make(string $token): Cookie
    {
        return Cookie::create(
            name: self::NAME,
            value: $token,
            expire: time() + (config('auth_cookie.ttl') * 60),
            path: config('auth_cookie.path'),
            domain: config('auth_cookie.domain'),
            secure: config('auth_cookie.secure'),
            httpOnly: true,
            raw: false,
            sameSite: config('auth_cookie.same_site'),
        );
    }

    /**
     * An already-expired, empty-valued cookie with the same attributes, so the
     * browser drops the stored one on logout.
     */
    public static function forget(): Cookie
    {
        return Cookie::create(
            name: self::NAME,
            value: '',
            expire: time() - 3600,
            path: config('auth_cookie.path'),
            domain: config('auth_cookie.domain'),
            secure: config('auth_cookie.secure'),
            httpOnly: true,
            raw: false,
            sameSite: config('auth_cookie.same_site'),
        );
    }
}

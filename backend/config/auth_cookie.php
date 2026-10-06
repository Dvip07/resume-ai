<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API Auth Token Cookie
    |--------------------------------------------------------------------------
    |
    | Open decision #6 (design.md) resolved to: the Sanctum personal access
    | token is delivered to the browser in a backend-set httpOnly cookie so
    | it is never readable from JavaScript. `AttachAuthTokenFromCookie`
    | promotes that cookie into an `Authorization: Bearer <token>` header so
    | `auth:sanctum` keeps working unchanged.
    |
    | The cookie is intentionally NOT encrypted by Laravel: `api/*` routes run
    | the `api` middleware group, which does not include `EncryptCookies`, so
    | there is no place that would decrypt it on the way in. The cookie name is
    | also listed in the `encryptCookies(except: ...)` list in bootstrap/app.php
    | so the value stays plaintext even if the cookie is ever seen by the `web`
    | group. The value is a Sanctum token (`id|random-40`), i.e. already an
    | opaque high-entropy secret — encryption would add no confidentiality
    | against anyone who can read the cookie jar.
    |
    | Cross-origin note: with the frontend on a different site in production,
    | the browser only sends the cookie on cross-site requests when
    | `SameSite=None; Secure`. Locally (Vite on http://localhost:5173 →
    | API on http://localhost:8000) the two are same-site (SameSite ignores
    | ports), so `Lax` over plain http works. Hence the env-driven defaults
    | below.
    |
    */

    // Lifetime in minutes; matches a "stay logged in for two weeks" default.
    'ttl' => (int) env('AUTH_COOKIE_TTL', 60 * 24 * 14),

    'path' => env('AUTH_COOKIE_PATH', '/'),

    'domain' => env('AUTH_COOKIE_DOMAIN'),

    // Must be true whenever same_site is 'none'.
    'secure' => (bool) env('AUTH_COOKIE_SECURE', env('APP_ENV') === 'production'),

    // 'lax' for local same-site dev, 'none' for a cross-site production
    // frontend (requires secure = true).
    'same_site' => env('AUTH_COOKIE_SAME_SITE', env('APP_ENV') === 'production' ? 'none' : 'lax'),

    /*
    | CSRF mitigation: because the credential is now an automatically-sent
    | cookie, the API only honours it when the request also carries
    | `X-Requested-With: XMLHttpRequest`. A cross-site HTML form post cannot
    | set a custom header, and fetch/XHR attempts to set it trigger a CORS
    | preflight that the explicit `allowed_origins` list rejects. Requests
    | that authenticate with an explicit `Authorization` header are unaffected
    | (that header is not sent automatically by the browser, so it is not a
    | CSRF vector).
    */
    'require_requested_with_header' => (bool) env('AUTH_COOKIE_REQUIRE_XRW', true),

];

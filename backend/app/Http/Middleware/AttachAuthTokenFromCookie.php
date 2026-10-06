<?php

namespace App\Http\Middleware;

use App\Support\AuthTokenCookie;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Promotes the httpOnly auth cookie into an `Authorization: Bearer <token>`
 * header when the request doesn't already carry one, so `auth:sanctum` works
 * identically for cookie-based browser clients and header-based API clients
 * (Requirements 1.5, 1.6).
 *
 * CSRF mitigation: an automatically-sent cookie is a CSRF vector, so the
 * cookie is only honoured when the request also carries
 * `X-Requested-With: XMLHttpRequest` — a header a cross-site HTML form cannot
 * set, and which forces a CORS preflight for fetch/XHR that the explicit
 * `cors.allowed_origins` list rejects. Requests carrying an explicit
 * `Authorization` header bypass this check because that header is never sent
 * automatically by the browser.
 */
class AttachAuthTokenFromCookie
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->headers->has('Authorization')) {
            return $next($request);
        }

        $token = $request->cookies->get(AuthTokenCookie::NAME);

        if (! is_string($token) || $token === '') {
            return $next($request);
        }

        if (config('auth_cookie.require_requested_with_header') && ! $this->hasRequestedWithHeader($request)) {
            return response()->json([
                'message' => 'Cookie-based authentication requires the '
                    .AuthTokenCookie::REQUIRED_HEADER.': '
                    .AuthTokenCookie::REQUIRED_HEADER_VALUE.' header.',
            ], 401);
        }

        $request->headers->set('Authorization', 'Bearer '.$token);

        return $next($request);
    }

    private function hasRequestedWithHeader(Request $request): bool
    {
        return strcasecmp(
            (string) $request->headers->get(AuthTokenCookie::REQUIRED_HEADER),
            AuthTokenCookie::REQUIRED_HEADER_VALUE
        ) === 0;
    }
}

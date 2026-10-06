<?php

use App\Http\Middleware\AttachAuthTokenFromCookie;
use App\Support\AuthTokenCookie;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Browser clients authenticate with a backend-set httpOnly cookie
        // (open decision #6); this promotes it to an Authorization header
        // before `auth:sanctum` runs.
        $middleware->api(prepend: [
            AttachAuthTokenFromCookie::class,
        ]);

        // The auth cookie is stored/read as plaintext everywhere. `api/*`
        // routes never run EncryptCookies, so exempting it here keeps the
        // value consistent if the cookie is ever handled by the `web` group.
        $middleware->encryptCookies(except: [
            AuthTokenCookie::NAME,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();

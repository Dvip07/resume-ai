<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AuthTokenCookie;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Register a new user and issue a Sanctum personal access token.
     *
     * The token is returned in the JSON body (for non-browser API clients)
     * and also set as an httpOnly cookie so the browser frontend never has to
     * keep it in JavaScript-readable storage (open decision #6).
     *
     * Stateless/bearer-token flow only — this does not touch the session
     * guard used by the Blade-based LoginRegistrationController.
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
        ], 201)->withCookie(AuthTokenCookie::make($token));
    }

    /**
     * Authenticate via email/password and issue a Sanctum personal access
     * token. Deliberately does NOT use Auth::attempt()/session auth — this
     * is a stateless bearer-token flow (Requirement 1.5).
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
        ])->withCookie(AuthTokenCookie::make($token));
    }

    /**
     * Revoke only the token used to authenticate the current request and
     * expire the auth cookie holding it.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully.',
        ])->withCookie(AuthTokenCookie::forget());
    }

    /**
     * Return the authenticated user.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json($request->user());
    }
}

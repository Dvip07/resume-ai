<?php

namespace App\Http\Controllers\Auth;

use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;

class LoginRegistrationController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'username' => 'required|string|max:250',
            'email' => 'required|email|max:250|unique:users',
            'password' => 'required|min:8|confirmed'
        ]);

        User::create([
            'name' => $request->username,
            'email' => $request->email,
            'role' => "Admin",
            'password' => Hash::make($request->password)
        ]);

        $credentials = $request->only('email', 'password');
        Auth::attempt($credentials);
        $request->session()->regenerate();
        return redirect()->route('authenticate-login')
            ->withSuccess('You have successfully registered & logged in!');
    }

    public function authenticate(Request $request)
    {

        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required'
        ]);

        $credentials = $request->only('email', 'password');
        $remember = $request->filled('remember');

        $user = User::where('email', $request->email)->first();
        if ($user) {
            if (Auth::attempt($credentials, $remember)) {
                $request->session()->regenerate();

                // The Blade dashboards this used to redirect to are gone
                // (Requirement 12.2) — the authenticated app is `frontend/`,
                // served from its own origin. The two branches here differed
                // only in which Blade dashboard they picked, so there is
                // nothing left to branch on.
                return redirect()->away(self::frontendUrl())
                    ->withSuccess('You have successfully logged in!');
            }
            return back()->withErrors([
                'email' => 'Your provided credentials do not match in our records.',
            ])->onlyInput('email');
        } else {
            return back()->withErrors("you are not aright role contact to admin");
        }
    }

    public function logout(Request $request)
    {
        $role = Auth::user()->role;
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        // if ($role == 'admin' || $role == 'account' || $role == 'warehouse') {
        //     $routeName = 'auth-login-basic';
        // } else {
        //     $routeName = 'auth-login-cover';
        // }
        return redirect()->route('authenticate-login')
            ->withSuccess('You have logged out successfully!');;
    }

    /**
     * Where the authenticated app lives. Same env var the CORS allow-list
     * reads, so the origin can't be configured in one place and not the other.
     */
    private static function frontendUrl(): string
    {
        return rtrim((string) config('app.frontend_url'), '/');
    }
}

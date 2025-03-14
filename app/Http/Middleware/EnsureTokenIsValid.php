<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Auth;

class EnsureTokenIsValid
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next)
    {
        if (Auth::check()) {

            if (auth()->user()->is_active == '1') {
                return $next($request);
            }
            // $role = Auth::user()->role;
            // if ($role == 'admin') {
            //     $routeName = 'auth-login-basic';
            // } else {
            //     $routeName = 'auth-login-cover';
            // }
            return redirect()->route('authenticate-login')->withErrors('You are Inactive,contact to admin');
        } else {

            return redirect()->route('authenticate-login')->withErrors('You are not login?');
        }
    }
}

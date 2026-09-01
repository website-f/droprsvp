<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bounces disabled (suspended) users: if the signed-in account has been disabled
 * by an admin, log it out and send it back to /login with a notice. Runs on the
 * web group — guests (no user) pass straight through.
 */
class EnsureAccountActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->isDisabled()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            $request->session()->flash('flash_warning', 'Your account has been disabled. Please contact support if you think this is a mistake.');

            return redirect()->route('login');
        }

        return $next($request);
    }
}

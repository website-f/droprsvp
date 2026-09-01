<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forces guest-created accounts to choose their own password before doing anything
 * else. They land here after signing in with the temporary password we emailed.
 */
class EnsurePasswordSet
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->must_set_password
            && ! $request->routeIs('password.set', 'password.set.save', 'logout')) {
            if ($request->expectsJson()) {
                return $next($request);
            }

            return redirect()->route('password.set');
        }

        return $next($request);
    }
}

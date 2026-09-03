<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-IP throttle for the Fortify auth POSTs that ship without a rate limiter
 * (registration + password-reset request/submit). Login and two-factor already
 * have named Fortify limiters; password-reset also has the 60s broker throttle,
 * so this is a belt-and-suspenders cap against account-creation spam and
 * reset-email bombing. Only acts on those named routes; everything else passes.
 */
class ThrottleAuthEndpoints
{
    private const LIMITED = ['register', 'password.email', 'password.update', 'password.confirm.store'];
    private const MAX = 8;         // attempts...
    private const DECAY = 60;      // ...per this many seconds, per IP + route

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('POST') && $request->routeIs(self::LIMITED)) {
            $key = 'auth-post:'.$request->route()?->getName().':'.$request->ip();

            if (RateLimiter::tooManyAttempts($key, self::MAX)) {
                abort(429, 'Too many attempts. Please try again in a minute.');
            }

            RateLimiter::hit($key, self::DECAY);
        }

        return $next($request);
    }
}

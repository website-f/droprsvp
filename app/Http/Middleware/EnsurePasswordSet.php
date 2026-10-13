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
    /**
     * Pages a brand-new buyer can always reach: the ones that show what they
     * just bought. Someone who ticked "keep me signed in" at checkout arrived
     * on their confirmation page and was bounced to "choose a password" before
     * seeing a single ticket. They are only ever reading their own tickets
     * here, so nothing is opened up; the confirmation page asks for the
     * password instead, and every other page still insists on it.
     */
    private const OPEN = [
        'password.set', 'password.set.save', 'logout',
        'checkout.confirmation', 'checkout.analytics.ack', 'checkout.analytics.claim',
        'tickets.show', 'account.tickets',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->must_set_password && ! $request->routeIs(...self::OPEN)) {
            if ($request->expectsJson()) {
                return $next($request);
            }

            return redirect()->route('password.set');
        }

        return $next($request);
    }
}

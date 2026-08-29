<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Consumer accounts must complete their "about you" profile before using the
 * app. Only redirects plain GET navigations (so logout / actions still work);
 * organizers + superadmins are exempt (they have their own onboarding).
 */
class EnsureAboutYou
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user
            && $user->profile_completed_at === null
            && ! $user->hasAnyRole(['organizer', 'superadmin'])
            && $request->isMethod('GET')
            && ! $request->expectsJson()
            && ! $request->routeIs('profile.about-you')) {
            return redirect()->route('profile.about-you');
        }

        return $next($request);
    }
}

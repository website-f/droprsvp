<?php

namespace App\Http\Middleware;

use App\Support\Profile;
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
            && ! $user->hasAnyRole(['organizer', 'superadmin', 'staff'])
            && $request->isMethod('GET')
            && ! $request->expectsJson()
            && ! $request->routeIs('profile.about-you')) {
            // Someone who already has everything the form would ask for is
            // simply marked done. Without this, every buyer from before the
            // form was cut down to a phone number would be stopped once more
            // to confirm details they had already given at checkout.
            if (Profile::isComplete($user)) {
                $user->forceFill(['profile_completed_at' => now()])->save();

                return $next($request);
            }

            return redirect()->route('profile.about-you');
        }

        return $next($request);
    }
}

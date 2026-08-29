<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Organizers must be approved before they can use the host area. Pending →
 * "under review" page; rejected → back to the application (to re-appeal).
 * Superadmins and grandfathered organizers (approved/no application) pass.
 */
class EnsureOrganizerApproved
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user && $user->hasRole('organizer') && ! $user->hasRole('superadmin') && $request->isMethod('GET') && ! $request->expectsJson()) {
            // A profile with any non-approved status (incomplete/pending/rejected)
            // is gated. Organizers with no profile are grandfathered (approved).
            $profile = $user->organizerProfile;
            if ($profile && $profile->status !== null && $profile->status !== 'approved') {
                return redirect()->route($profile->status === 'pending' ? 'host.pending' : 'host.apply');
            }
        }

        return $next($request);
    }
}

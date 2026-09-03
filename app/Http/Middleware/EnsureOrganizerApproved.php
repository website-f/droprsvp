<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Organizers must be approved before they can use the host area. Pending →
 * "under review" page; incomplete/rejected/unknown → the application (to submit
 * or re-appeal). Superadmins, grandfathered organizers (no application on file),
 * and team collaborators pass. GET requests are redirected to the right page;
 * write requests (POST/PUT/DELETE) are hard-blocked so the gate can't be skipped
 * by POSTing directly with a valid CSRF token.
 */
class EnsureOrganizerApproved
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (! $user || ! $user->hasRole('organizer') || $user->hasRole('superadmin')) {
            return $next($request);
        }

        // Collaborators may reach the host panel to manage events shared with them
        // (per-event access is still enforced by EventPolicy).
        if ($user->teamMemberships()->exists()) {
            return $next($request);
        }

        $profile = $user->organizerProfile;

        // Grandfathered: legacy organizers with no application profile at all, or
        // an already-approved application.
        if (! $profile || $profile->status === 'approved') {
            return $next($request);
        }

        // Not approved (incomplete / pending / rejected / null). Block any write so
        // the approval requirement can't be bypassed by a direct POST.
        if (! $request->isMethod('GET') || $request->expectsJson()) {
            abort(403, 'Your organizer account is still pending approval.');
        }

        return redirect()->route($profile->status === 'pending' ? 'host.pending' : 'host.apply');
    }
}

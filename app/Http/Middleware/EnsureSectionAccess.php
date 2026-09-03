<?php

namespace App\Http\Middleware;

use App\Support\RolePermissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates admin routes per section for staff ("admin") accounts, using the
 * superadmin-managed permission matrix. Superadmins always pass. A staff user
 * hitting a section they aren't granted (or an unmapped admin route) gets a 403.
 */
class EnsureSectionAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // role:superadmin|staff already guards the group; superadmins have full access.
        if ($user && $user->hasRole('superadmin')) {
            return $next($request);
        }

        $section = RolePermissions::sectionForRoute($request->route()?->getName());

        abort_unless($section && RolePermissions::can($user, $section), 403);

        return $next($request);
    }
}

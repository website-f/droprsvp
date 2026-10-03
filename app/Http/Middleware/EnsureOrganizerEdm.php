<?php

namespace App\Http\Middleware;

use App\Support\Edm\OrganizerRules;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The organizer's Email marketing area, behind the rules a superadmin sets
 * (EDM → Organizer rules, and per organizer in EDM → Organizers).
 *
 * Without access, every page shows why instead — and how to get it, when
 * going Premium is the answer. The credits checkout return stays reachable so
 * a payment made just before a switch-off still lands somewhere sensible.
 */
class EnsureOrganizerEdm
{
    public function handle(Request $request, Closure $next, ?string $feature = null): Response
    {
        $user = $request->user();
        $access = OrganizerRules::access($user);

        if (! $access['allowed'] && ! $request->routeIs('host.edm.credits.return')) {
            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                return response()->json(['message' => $access['reason']], 403);
            }

            return Inertia::render('host/edm/locked', [
                'reason' => $access['reason'],
                'upgrade' => $access['upgrade'],
            ])->toResponse($request)->setStatusCode(403);
        }

        // Feature pages (automations, own domains) can be switched off on their own.
        if ($feature && $access['allowed'] && ! OrganizerRules::for($user->id)[$feature]) {
            return Inertia::render('host/edm/locked', [
                'reason' => $feature === 'automations'
                    ? 'Automations are not available on your account.'
                    : 'Sending from your own domain is not available on your account. Your emails go out from DropRSVP’s address under your name.',
                'upgrade' => false,
                'back' => '/host/edm',
            ])->toResponse($request)->setStatusCode(403);
        }

        return $next($request);
    }
}

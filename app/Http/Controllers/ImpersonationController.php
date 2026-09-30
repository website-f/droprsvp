<?php

namespace App\Http\Controllers;

use App\Support\Impersonation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Leaving a "view as" session.
 *
 * Deliberately NOT under the admin routes: the request arrives authenticated as
 * the person being viewed, who has no admin access at all, so gating it behind
 * `role:superadmin` would trap the actor inside the borrowed account with no
 * way out but clearing cookies.
 */
class ImpersonationController extends Controller
{
    public function stop(Request $request): RedirectResponse
    {
        $actor = Impersonation::stop($request);

        if (! $actor) {
            // Nothing to go back to: either this was never an impersonated
            // session, or the account that started it has since lost its
            // superadmin role. Either way, end the session rather than leave
            // somebody signed in as a user they are not.
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        }

        return redirect()
            ->route('admin.users.index')
            ->with('flash_success', 'Back to your own account.');
    }
}

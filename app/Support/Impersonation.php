<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * "View as" — a superadmin borrowing another account's point of view.
 *
 * Support questions are almost always about something only the reporter can
 * see: a host whose event won't publish, a buyer who can't find their ticket.
 * Reading the database tells you what the rows say, not what the screen says,
 * and the two differ precisely where the bugs are.
 *
 * The rules are deliberately narrow, because this is the strongest thing an
 * admin can do:
 *
 *   * superadmin only — never staff, whose whole point is limited access;
 *   * never onto another superadmin, so it cannot be used to escalate sideways
 *     into an account the actor is not already equivalent to;
 *   * never onto a disabled account (EnsureAccountActive would eject them
 *     mid-request anyway) or a deleted one;
 *   * never nested — one hop, always back to the same person;
 *   * always visible, via a banner the impersonated session cannot dismiss;
 *   * both ends written to the log, so there is a record that someone looked.
 *
 * The original id lives in the session rather than a signed cookie or a second
 * guard: it dies with the session, so a closed browser ends the impersonation
 * rather than leaving it parked.
 */
class Impersonation
{
    /** Session key holding the id of the superadmin who started this. */
    public const KEY = 'impersonator_id';

    /** Is the current request a borrowed session? */
    public static function active(Request $request): bool
    {
        return $request->session()->has(self::KEY);
    }

    /** The superadmin behind a borrowed session, if any. */
    public static function actor(Request $request): ?User
    {
        // (int) so find() resolves to one model: handed a mixed value it may
        // also be an array, and then it returns a collection.
        $id = (int) $request->session()->get(self::KEY);

        return $id > 0 ? User::query()->find($id) : null;
    }

    /** Why this user may not be impersonated, or null when they may be. */
    public static function refusalFor(User $actor, User $target): ?string
    {
        if (! $actor->hasRole('superadmin')) {
            return 'Only a superadmin can view the site as another user.';
        }

        if ($actor->id === $target->id) {
            return 'That is already you.';
        }

        if ($target->hasRole('superadmin')) {
            return 'Superadmin accounts can’t be viewed as — they already see everything you do.';
        }

        if ($target->isDisabled()) {
            return 'That account is disabled. Re-enable it first if you need to see what they see.';
        }

        return null;
    }

    /**
     * Swap the session onto $target, remembering who to come back as.
     *
     * The session is regenerated (which keeps its data, so the marker survives)
     * to avoid carrying the admin's session id onto the borrowed identity.
     */
    public static function start(Request $request, User $actor, User $target): void
    {
        $request->session()->put(self::KEY, $actor->id);

        Auth::guard('web')->login($target);
        $request->session()->regenerate();

        Log::info('impersonation.start', [
            'actor_id' => $actor->id,
            'actor_email' => $actor->email,
            'target_id' => $target->id,
            'target_email' => $target->email,
            'ip' => $request->ip(),
        ]);
    }

    /**
     * Hand the session back to whoever started it.
     *
     * The stored id is re-checked against the superadmin role at this point,
     * not just at the start: a session parked open across a role change must
     * not be a way back into an account that has since lost its privileges.
     *
     * @return User|null The restored superadmin, or null when there was nothing
     *                   to restore (in which case the caller should log out).
     */
    public static function stop(Request $request): ?User
    {
        $id = (int) $request->session()->pull(self::KEY);

        if ($id <= 0) {
            return null;
        }

        $actor = User::query()->find($id);

        if (! $actor || ! $actor->hasRole('superadmin') || $actor->isDisabled()) {
            return null;
        }

        Log::info('impersonation.stop', [
            'actor_id' => $actor->id,
            'target_id' => Auth::id(),
            'ip' => $request->ip(),
        ]);

        Auth::guard('web')->login($actor);
        $request->session()->regenerate();

        return $actor;
    }

    /**
     * Banner payload for the frontend, or null when this is an ordinary session.
     *
     * @return array{actor: string|null, viewing: string|null, viewing_email: string|null, role: string}|null
     */
    public static function share(Request $request): ?array
    {
        if (! self::active($request)) {
            return null;
        }

        $viewing = $request->user();

        return [
            'actor' => self::actor($request)?->name,
            'viewing' => $viewing?->name,
            'viewing_email' => $viewing?->email,
            // What they are seeing the site AS, so the banner can say "as an
            // organizer" rather than just naming a person.
            'role' => match (true) {
                (bool) $viewing?->hasRole('organizer') => 'organizer',
                (bool) $viewing?->hasRole('staff') => 'staff',
                default => 'normal user',
            },
        ];
    }
}

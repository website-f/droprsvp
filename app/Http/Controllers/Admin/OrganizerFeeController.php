<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use App\Support\PlatformFee;
use Illuminate\Http\Request;

/**
 * Per-organizer booking-fee overrides. The global rate (Settings → Payments)
 * applies to everyone by default; from here an admin can move one organizer onto
 * a rate of their own — for a negotiated partner deal, a promo period, or a
 * high-volume host.
 *
 * An override only affects orders opened AFTER it's saved: CheckoutService freezes
 * the fee onto each order, so past sales, receipts and payouts never move.
 */
class OrganizerFeeController extends Controller
{
    /** The organizer list with the fee each one is actually charged. */
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $scope = in_array($request->query('scope'), ['custom', 'global'], true) ? $request->query('scope') : 'all';

        $organizers = User::query()
            // Anyone who hosts: the organizer role, or events on file (grandfathered hosts).
            ->where(fn ($w) => $w->whereHas('roles', fn ($r) => $r->where('name', 'organizer'))->orHas('events'))
            ->when($q !== '', fn ($x) => $x->where(fn ($w) => $w->where('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%")))
            ->when($scope === 'custom', fn ($x) => $x->where(fn ($w) => $w->whereNotNull('platform_fee_percent')->orWhereNotNull('platform_fee_flat')))
            ->when($scope === 'global', fn ($x) => $x->whereNull('platform_fee_percent')->whereNull('platform_fee_flat'))
            ->withCount('events')
            // Booking fees the platform has actually collected on this host's events.
            ->addSelect(['fees_earned' => Order::query()
                ->selectRaw('coalesce(sum(orders.fees), 0)')
                ->join('events', 'events.id', '=', 'orders.event_id')
                ->whereColumn('events.user_id', 'users.id')
                ->whereIn('orders.status', ['paid', 'refunded']),
            ])
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'events' => $u->events_count,
                'fees_earned' => round((float) $u->fees_earned, 2),
                'fee' => PlatformFee::toArray($u),
            ]);

        return inertia('admin/organizer-fees/index', [
            'organizers' => $organizers,
            'filters' => ['q' => $q, 'scope' => $scope],
            'global' => PlatformFee::toArray(),
            'counts' => [
                'custom' => User::where(fn ($w) => $w->whereNotNull('platform_fee_percent')->orWhereNotNull('platform_fee_flat'))->count(),
            ],
        ]);
    }

    /** Put one organizer on their own rate. Reached from this page and the user's admin page. */
    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'fee_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'fee_flat' => ['required', 'numeric', 'min:0', 'max:100000'],
        ]);

        $user->setFeeOverride((float) $data['fee_percent'], (float) $data['fee_flat']);

        return back()->with('flash_success', "{$user->name} is now on a custom booking fee — ".PlatformFee::label($user->fresh()).'.');
    }

    /** Hand an organizer back to the global rate. */
    public function destroy(User $user)
    {
        $user->setFeeOverride(null, null);

        return back()->with('flash_success', "{$user->name} is back on the global booking fee.");
    }
}

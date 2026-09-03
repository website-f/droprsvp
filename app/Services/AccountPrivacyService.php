<?php

namespace App\Services;

use App\Models\EventComment;
use App\Models\EventReview;
use App\Models\Order;
use App\Models\Payout;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * PDPA (Personal Data Protection Act) support: let a user download everything we
 * hold about them, and erase their personal data on request. Erasure anonymises
 * the PII on records we must keep for accounting (orders, tickets) and scrubs the
 * account itself, rather than hard-deleting rows and breaking financial history.
 */
class AccountPrivacyService
{
    /** A portable snapshot of everything tied to this account. */
    public function export(User $user): array
    {
        $orders = $this->ownedOrders($user)->with(['event:id,title', 'tickets:id,order_id,attendee_name,status,ticket_type_id', 'tickets.ticketType:id,name'])->latest()->get();

        return [
            'exported_at' => now()->toIso8601String(),
            'account' => [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'gender' => $user->gender,
                'age_band' => $user->age_band,
                'city' => $user->city,
                'country' => $user->country,
                'roles' => $user->getRoleNames()->all(),
                'notification_preferences' => $user->notificationSettings(),
                'joined_at' => optional($user->created_at)->toIso8601String(),
            ],
            'orders' => $orders->map(fn (Order $o) => [
                'reference' => $o->reference,
                'event' => $o->event?->title,
                'status' => $o->status,
                'total' => (float) $o->total,
                'currency' => $o->currency,
                'placed_at' => optional($o->created_at)->toIso8601String(),
                'paid_at' => optional($o->paid_at)->toIso8601String(),
                'tickets' => $o->tickets->map(fn (Ticket $t) => [
                    'attendee_name' => $t->attendee_name,
                    'type' => $t->ticketType?->name,
                    'status' => $t->status,
                ])->all(),
            ])->all(),
            'reviews' => EventReview::where('user_id', $user->id)->with('event:id,title')->latest()->get()
                ->map(fn (EventReview $r) => [
                    'event' => $r->event?->title, 'rating' => $r->rating, 'body' => $r->body,
                    'at' => optional($r->created_at)->toIso8601String(),
                ])->all(),
            'comments' => EventComment::where('user_id', $user->id)->with('event:id,title')->latest()->get()
                ->map(fn (EventComment $c) => [
                    'event' => $c->event?->title, 'body' => $c->body,
                    'at' => optional($c->created_at)->toIso8601String(),
                ])->all(),
            'following' => $user->following()->pluck('name')->all(),
        ];
    }

    /**
     * Human-readable reasons the account can't be deleted yet (empty = good to go).
     * Guards against orphaning attendees or losing money in flight.
     */
    public function deletionBlockers(User $user): array
    {
        $blockers = [];

        if ($user->hasRole('superadmin')) {
            $blockers[] = 'Superadmin accounts can’t be self-deleted. Ask another superadmin to remove your access first.';
        }

        $upcoming = $user->events()
            ->where('status', 'published')
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '>=', now()))
            ->count();
        if ($upcoming > 0) {
            $blockers[] = "You have {$upcoming} upcoming published event".($upcoming === 1 ? '' : 's').'. Cancel or reassign '.($upcoming === 1 ? 'it' : 'them').' before deleting your account.';
        }

        $payouts = Payout::where('user_id', $user->id)->whereIn('status', ['pending', 'processing'])->count();
        if ($payouts > 0) {
            $blockers[] = 'You have a payout in progress. It needs to settle before your account can be deleted.';
        }

        return $blockers;
    }

    /** Anonymise the PII we retain, then soft-delete the account. */
    public function erase(User $user): void
    {
        DB::transaction(function () use ($user) {
            // Scrub the buyer PII on the account's own orders (financial totals stay).
            $orderIds = Order::where('user_id', $user->id)->pluck('id');
            Order::whereIn('id', $orderIds)->update([
                'buyer_name' => 'Deleted user', 'buyer_email' => null, 'buyer_phone' => null, 'buyer_city' => null,
            ]);
            Ticket::whereIn('order_id', $orderIds)->update(['attendee_name' => 'Deleted user', 'attendee_email' => null]);

            // Scrub the account itself, keeping the row for referential integrity.
            $user->forceFill([
                'name' => 'Deleted user',
                'email' => 'deleted-'.$user->id.'@deleted.invalid',
                'phone' => null, 'avatar' => null, 'google_id' => null, 'city' => null, 'country' => null,
                'gender' => null, 'age_band' => null, 'notification_preferences' => null,
            ])->save();

            $user->delete(); // soft delete
        });
    }

    /** Orders owned by the account — by id, or placed as a guest with its email. */
    private function ownedOrders(User $user)
    {
        return Order::query()->where(function ($q) use ($user) {
            $q->where('user_id', $user->id);
            if ($user->email) {
                $q->orWhere('buyer_email', $user->email);
            }
        });
    }
}

<?php

namespace App\Support;

use App\Models\Event;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The people side of the superadmin's analytics: sign-ups, customers and
 * organizers, over the same window as the rest of the page.
 *
 * A customer is an email that has paid for something — a guest who checked out
 * without an account is as much a customer as a member, so customers are
 * counted by buyer email, not by user id.
 */
class PlatformInsights
{
    /** @return array<string,mixed> */
    public static function users(array $w): array
    {
        $signups = User::query()->whereBetween('created_at', [$w['from'], $w['to']])
            ->selectRaw('DATE(created_at) as d, COUNT(*) as c')->groupBy('d')->pluck('c', 'd');

        $paidAll = Order::query()->whereNotNull('paid_at')->whereNotNull('buyer_email');
        $paidWindow = (clone $paidAll)->whereBetween('paid_at', [$w['from'], $w['to']]);

        $customers = (int) (clone $paidAll)->distinct()->count(DB::raw('LOWER(buyer_email)'));
        $customersWindow = (int) (clone $paidWindow)->distinct()->count(DB::raw('LOWER(buyer_email)'));

        // Bought for two or more different events, ever.
        $repeat = (int) DB::query()->fromSub(
            (clone $paidAll)->selectRaw('LOWER(buyer_email) as e')->groupByRaw('LOWER(buyer_email)')
                ->havingRaw('COUNT(DISTINCT event_id) > 1'),
            'repeaters',
        )->count();

        $guestOrders = (int) (clone $paidWindow)->whereNull('user_id')->count();
        $memberOrders = (int) (clone $paidWindow)->whereNotNull('user_id')->count();

        // First-time vs returning, for buyers in the window: returning means
        // they had paid for something before the window opened.
        $returning = (int) DB::query()->fromSub(
            (clone $paidWindow)->selectRaw('DISTINCT LOWER(buyer_email) as e'),
            'w',
        )->whereExists(fn ($q) => $q->from('orders as earlier')
            ->whereRaw('LOWER(earlier.buyer_email) = w.e')
            ->whereNotNull('earlier.paid_at')
            ->where('earlier.paid_at', '<', $w['from']))
            ->count();

        return [
            'kpis' => [
                'total' => User::count(),
                'new' => (int) $signups->sum(),
                'organizers' => User::whereHas('roles', fn ($q) => $q->where('name', 'organizer'))->count(),
                'customers' => $customers,
                'customers_window' => $customersWindow,
                'repeat' => $repeat,
                'repeat_rate' => $customers > 0 ? round($repeat / $customers * 100, 1) : 0.0,
            ],
            'signups' => Analytics::bucketed($w, fn ($d) => ['signups' => (int) ($signups[$d->toDateString()] ?? 0)], ['signups' => 0]),
            'checkoutType' => array_values(array_filter([
                ['name' => 'Signed-in members', 'value' => $memberOrders],
                ['name' => 'Guest checkout', 'value' => $guestOrders],
            ], fn ($s) => $s['value'] > 0)),
            'newVsReturning' => array_values(array_filter([
                ['name' => 'First-time buyers', 'value' => max(0, $customersWindow - $returning)],
                ['name' => 'Returning buyers', 'value' => $returning],
            ], fn ($s) => $s['value'] > 0)),
        ];
    }

    /** Biggest spenders in the window. @return list<array<string,mixed>> */
    public static function topCustomers(array $w, callable $audience, int $limit = 10): array
    {
        $rows = $audience(Order::query())
            ->whereNotNull('paid_at')->whereNotNull('buyer_email')
            ->where('status', 'paid')
            ->whereBetween('paid_at', [$w['from'], $w['to']])
            ->selectRaw('LOWER(buyer_email) as email, MAX(buyer_name) as name, MAX(buyer_phone) as phone, COUNT(*) as orders, COUNT(DISTINCT event_id) as events, SUM(total - refunded_amount) as spend, MAX(paid_at) as last_paid')
            ->groupByRaw('LOWER(buyer_email)')
            ->orderByDesc('spend')
            ->limit($limit)
            ->get();

        return $rows->map(fn ($r) => [
            'name' => $r->name,
            'email' => $r->email,
            'phone' => $r->phone,
            'orders' => (int) $r->orders,
            'events' => (int) $r->events,
            'spend' => round((float) $r->spend, 2),
            'last' => $r->last_paid ? Carbon::parse($r->last_paid)->setTimezone(Dates::tz())->format('j M Y') : null,
        ])->all();
    }

    /** Organizers ranked by takings in the window. @return list<array<string,mixed>> */
    public static function topOrganizers(array $w, int $limit = 10): array
    {
        $rows = Order::query()
            ->join('events', 'events.id', '=', 'orders.event_id')
            ->where('orders.status', 'paid')
            ->whereBetween('orders.paid_at', [$w['from'], $w['to']])
            ->selectRaw('events.user_id as uid, COUNT(DISTINCT orders.event_id) as events, COUNT(*) as orders, SUM(orders.total - orders.refunded_amount) as revenue, SUM(orders.fees) as fees')
            ->groupBy('events.user_id')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get();

        $users = User::with('organizerProfile:id,user_id,business_name')
            ->whereIn('id', $rows->pluck('uid'))->get(['id', 'name', 'slug'])->keyBy('id');
        $live = Event::query()->whereIn('user_id', $rows->pluck('uid'))->published()->notEnded()
            ->selectRaw('user_id, COUNT(*) as c')->groupBy('user_id')->pluck('c', 'user_id');

        return $rows->map(function ($r) use ($users, $live) {
            $u = $users->get($r->uid);

            return [
                'name' => $u ? ($u->organizerProfile?->business_name ?: $u->name) : '—',
                'slug' => $u?->slug,
                'events' => (int) $r->events,
                'live' => (int) ($live[$r->uid] ?? 0),
                'orders' => (int) $r->orders,
                'revenue' => round((float) $r->revenue, 2),
                'fees' => round((float) $r->fees, 2),
            ];
        })->all();
    }
}

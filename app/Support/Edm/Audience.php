<?php

namespace App\Support\Edm;

use App\Models\EmailConsent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Who a campaign goes to.
 *
 * Always starts from the consent ledger — subscribed to this list, and never
 * suppressed — and filters can only NARROW that. There is no filter that adds
 * anyone, so no combination of choices in the builder can reach a person who
 * did not opt in.
 *
 * Purchase filters match orders by buyer email rather than by user id, because
 * many buyers check out as guests. The comparison lower-cases the order side:
 * consent addresses are stored normalised, but order emails are stored as
 * typed, and SQLite compares case-sensitively where MySQL would not.
 */
final class Audience
{
    /**
     * The filter shape, with defaults. Anything else in a saved spec is ignored.
     *
     * @return array{cities:array<int,string>,event_ids:array<int,int>,category_ids:array<int,int>,purchased_within_days:?int,purchase:string}
     */
    public static function normalise(?array $spec): array
    {
        $spec = (array) $spec;

        $ints = fn ($v) => array_values(array_unique(array_filter(array_map('intval', (array) $v), fn ($i) => $i > 0)));

        $days = isset($spec['purchased_within_days']) && (int) $spec['purchased_within_days'] > 0
            ? min(3650, (int) $spec['purchased_within_days'])
            : null;

        return [
            'cities' => array_values(array_unique(array_filter(array_map(fn ($c) => trim((string) $c), (array) ($spec['cities'] ?? []))))),
            'event_ids' => $ints($spec['event_ids'] ?? []),
            'category_ids' => $ints($spec['category_ids'] ?? []),
            'purchased_within_days' => $days,
            // any | buyers (have bought) | non_buyers (never bought)
            'purchase' => in_array($purchase = $spec['purchase'] ?? 'any', ['any', 'buyers', 'non_buyers'], true) ? $purchase : 'any',
        ];
    }

    /** The recipients, as consent rows (email, user_id) with the user's name. */
    public static function query(?array $spec, ?int $organizerId = null): Builder
    {
        $f = self::normalise($spec);

        $query = EmailConsent::query()
            ->from('email_consents')
            ->leftJoin('users', 'users.id', '=', 'email_consents.user_id')
            ->where('email_consents.scope', Consent::scope($organizerId))
            ->where('email_consents.status', 'subscribed')
            ->whereNotExists(fn (QueryBuilder $q) => $q->select(DB::raw(1))
                ->from('email_suppressions')
                ->whereColumn('email_suppressions.email', 'email_consents.email'))
            ->select('email_consents.email', 'email_consents.user_id', 'users.name');

        if ($f['cities']) {
            // Their profile city, or the city they gave at any checkout.
            $query->where(fn (Builder $w) => $w
                ->whereIn('users.city', $f['cities'])
                ->orWhereExists(fn (QueryBuilder $q) => self::orders($q)->whereIn('orders.buyer_city', $f['cities'])));
        }

        if ($f['event_ids']) {
            $query->whereExists(fn (QueryBuilder $q) => self::paidOrders($q)->whereIn('orders.event_id', $f['event_ids']));
        }

        if ($f['category_ids']) {
            $query->whereExists(fn (QueryBuilder $q) => self::paidOrders($q)
                ->join('events', 'events.id', '=', 'orders.event_id')
                ->whereIn('events.category_id', $f['category_ids']));
        }

        if ($f['purchased_within_days']) {
            $query->whereExists(fn (QueryBuilder $q) => self::paidOrders($q)
                ->where('orders.paid_at', '>=', now()->subDays($f['purchased_within_days'])));
        }

        if ($f['purchase'] === 'buyers') {
            $query->whereExists(fn (QueryBuilder $q) => self::paidOrders($q));
        } elseif ($f['purchase'] === 'non_buyers') {
            $query->whereNotExists(fn (QueryBuilder $q) => self::paidOrders($q));
        }

        return $query;
    }

    public static function count(?array $spec, ?int $organizerId = null): int
    {
        return self::query($spec, $organizerId)->count();
    }

    /**
     * Who the one-off re-permission email may go to.
     *
     * Everyone we have a relationship with — an account, or a paid order as a
     * guest — who has NEVER made a marketing choice with DropRSVP: no opt-in,
     * no opt-out. Never suppressed. And never asked before: a person receives
     * at most one re-permission email, ever. Asking twice is how a polite
     * question turns into the spam it was meant to avoid.
     *
     * $exceptCampaign is the campaign being built. Its own rows must not count
     * as "already asked" while it is being filled — the list is inserted in
     * chunks, and without this each chunk would drop out of the query it came
     * from, shifting the next page and silently skipping people.
     */
    public static function repermission(?int $exceptCampaign = null): QueryBuilder
    {
        $accounts = DB::table('users')
            ->selectRaw('LOWER(users.email) as email, users.id as user_id, users.name as name')
            ->whereNotNull('users.email')
            ->where('users.email', '!=', '');

        $buyers = DB::table('orders')
            ->selectRaw('LOWER(orders.buyer_email) as email, orders.user_id as user_id, orders.buyer_name as name')
            ->whereIn('orders.status', ['paid', 'refunded'])
            ->whereNotNull('orders.buyer_email')
            ->where('orders.buyer_email', '!=', '');

        return DB::query()
            ->fromSub($accounts->unionAll($buyers), 'people')
            ->selectRaw('people.email as email, MAX(people.user_id) as user_id, MAX(people.name) as name')
            ->whereNotExists(fn (QueryBuilder $q) => $q->select(DB::raw(1))
                ->from('email_consents')
                ->whereColumn('email_consents.email', 'people.email')
                ->where('email_consents.scope', Consent::PLATFORM))
            ->whereNotExists(fn (QueryBuilder $q) => $q->select(DB::raw(1))
                ->from('email_suppressions')
                ->whereColumn('email_suppressions.email', 'people.email'))
            ->whereNotExists(fn (QueryBuilder $q) => $q->select(DB::raw(1))
                ->from('email_sends')
                ->join('email_campaigns', 'email_campaigns.id', '=', 'email_sends.campaign_id')
                ->whereColumn('email_sends.email', 'people.email')
                ->where('email_campaigns.kind', 'repermission')
                ->when($exceptCampaign, fn ($w) => $w->where('email_campaigns.id', '!=', $exceptCampaign)))
            ->groupBy('people.email');
    }

    public static function repermissionCount(?int $exceptCampaign = null): int
    {
        return DB::query()->fromSub(self::repermission($exceptCampaign), 'r')->count();
    }

    /** Orders placed under this consent row's address. */
    private static function orders(QueryBuilder $q): QueryBuilder
    {
        return $q->select(DB::raw(1))
            ->from('orders')
            ->whereRaw('LOWER(orders.buyer_email) = email_consents.email');
    }

    private static function paidOrders(QueryBuilder $q): QueryBuilder
    {
        return self::orders($q)->whereIn('orders.status', ['paid', 'refunded'])->whereNotNull('orders.paid_at');
    }
}

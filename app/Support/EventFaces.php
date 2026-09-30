<?php

namespace App\Support;

use App\Models\Order;
use Illuminate\Support\Collection;

/**
 * The handful of attendee names shown as "faces" on an event card.
 *
 * Built for every card on a page in ONE query. It used to be one query per
 * card, inside the card() mappers in DiscoverController and HomeController —
 * so a 12-event browse page spent 13 round trips on MySQL just to draw three
 * names per tile, and the cost grew with the page size. That is the sort of
 * per-request multiplier that turns ordinary crawler traffic into a database
 * the host suspends you for.
 *
 * The GROUP BY does the de-duplication in MySQL rather than fetching every paid
 * order and uniquing in PHP: a popular event can have thousands of orders, and
 * only its distinct buyer names are ever wanted.
 */
class EventFaces
{
    /** How many names a card shows. */
    private const PER_EVENT = 3;

    /**
     * @param  iterable<int|string>  $eventIds
     * @return array<int|string, list<string>> event id => up to 3 buyer names
     */
    public static function for(iterable $eventIds): array
    {
        $ids = collect($eventIds)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        // One row per (event, distinct buyer name), most recent purchase first.
        $rows = Order::query()
            ->selectRaw('event_id, buyer_name, MAX(paid_at) as last_paid_at')
            ->whereIn('event_id', $ids)
            ->where('status', 'paid')
            ->whereNotNull('buyer_name')
            ->groupBy('event_id', 'buyer_name')
            ->orderByDesc('last_paid_at')
            ->get();

        return $rows
            ->groupBy('event_id')
            ->map(fn (Collection $group) => $group
                ->take(self::PER_EVENT)
                ->pluck('buyer_name')
                ->all())
            ->all();
    }
}

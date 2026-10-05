<?php

namespace App\Support;

use App\Models\Ticket;
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
 * The GROUP BY does the de-duplication in MySQL rather than fetching every
 * ticket and uniquing in PHP: a popular event can have thousands of them, and
 * only its distinct attendee names are ever wanted.
 *
 * Names come from the tickets rather than the orders so that the faces match
 * the "going" count beside them (one buyer, three tickets, one face), and so a
 * ticket passed on shows whoever holds it now.
 */
class EventFaces
{
    /** How many names a card shows. */
    private const PER_EVENT = 3;

    /**
     * @param  iterable<int|string>  $eventIds
     * @return array<int|string, list<string>> event id => up to 3 attendee names
     */
    public static function for(iterable $eventIds): array
    {
        $ids = collect($eventIds)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        // One row per (event, distinct attendee name), newest ticket first.
        $rows = Ticket::query()
            ->selectRaw('event_id, attendee_name, MAX(id) as last_id')
            ->whereIn('event_id', $ids)
            ->live()
            ->whereNotNull('attendee_name')
            ->where('attendee_name', '!=', '')
            ->groupBy('event_id', 'attendee_name')
            ->orderByDesc('last_id')
            ->get();

        return $rows
            ->groupBy('event_id')
            ->map(fn (Collection $group) => $group
                ->take(self::PER_EVENT)
                ->pluck('attendee_name')
                ->all())
            ->all();
    }
}

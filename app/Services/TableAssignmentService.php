<?php

namespace App\Services;

use App\Models\Event;
use Illuminate\Support\Collection;

class TableAssignmentService
{
    /**
     * Seat every not-yet-assigned admission (valid or checked-in) at a table with
     * free capacity, spreading people across tables (most-free first). Returns the
     * number of tickets seated.
     */
    public function autoAssign(Event $event): int
    {
        $tickets = $event->tickets()
            ->whereIn('status', ['valid', 'checked_in'])
            ->whereNull('seating_table_id')
            ->orderBy('id')->get();

        return $this->assign($event, $tickets);
    }

    /**
     * Place the given tickets at tables with remaining capacity. Used both by the
     * organizer's "auto-assign" button and, when the event opts in, at purchase time.
     *
     * @param  Collection<int, \App\Models\Ticket>  $tickets
     */
    public function assign(Event $event, Collection $tickets): int
    {
        $tables = $event->seatingTables()->withCount('tickets')->orderBy('sort_order')->get();
        if ($tables->isEmpty() || $tickets->isEmpty()) {
            return 0;
        }

        // Remaining seats per table, as a plain array so we can decrement in place.
        $free = $tables->mapWithKeys(fn ($t) => [$t->id => max(0, (int) $t->capacity - (int) $t->tickets_count)])->all();

        $assigned = 0;
        foreach ($tickets as $ticket) {
            if ($ticket->seating_table_id) {
                continue;
            }
            // The table with the most space left, so parties spread out evenly.
            $tableId = null;
            $best = 0;
            foreach ($free as $id => $n) {
                if ($n > $best) {
                    $best = $n;
                    $tableId = $id;
                }
            }
            if ($tableId === null) {
                break; // every table is full
            }
            $ticket->update(['seating_table_id' => $tableId]);
            $free[$tableId]--;
            $assigned++;
        }

        return $assigned;
    }
}

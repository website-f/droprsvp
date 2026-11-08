<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SeatingTable;
use App\Models\Ticket;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Who sits at which table, for events run in "tables" mode.
 *
 * A seat at a table is taken by a live admission (valid or checked in) seated
 * there, or held by a pending order whose buyer picked that table at checkout
 * — held the same way ticket stock is, until the order is paid or released as
 * stale. Refunded and cancelled tickets keep their table id for the record but
 * no longer take a seat.
 *
 * When the event's ticket types are its sittings (tables_per_ticket_type —
 * "Session A", "Session B" at the same tables), seats are counted per ticket
 * type: a table full for Session A is still free for Session B.
 */
class TableAssignmentService
{
    /**
     * Seat every not-yet-assigned admission (valid or checked-in) at a table with
     * free capacity, keeping each order's party together where a table fits it.
     * Returns the number of tickets seated.
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
     * Place the given tickets at tables with remaining capacity. Used by the
     * organizer's "auto-assign" button and, when the event opts in, at purchase.
     *
     * Each order's tickets (per ticket type) go to one table when one has room
     * for all of them — a group of three that bought together sits together —
     * choosing the fullest table that still fits, so big gaps stay open for big
     * groups. A party no table can hold is spread over the emptiest tables.
     *
     * @param  Collection<int, Ticket>  $tickets
     */
    public function assign(Event $event, Collection $tickets): int
    {
        $tables = $event->seatingTables()->orderBy('sort_order')->get(['id', 'capacity']);
        $tickets = $tickets->filter(fn (Ticket $t) => ! $t->seating_table_id);

        if ($tables->isEmpty() || $tickets->isEmpty()) {
            return 0;
        }

        $assigned = 0;
        $free = [];   // sitting key => [table id => seats left]

        foreach ($tickets->groupBy(fn (Ticket $t) => $t->order_id.'|'.$this->sittingKey($event, $t->ticket_type_id)) as $party) {
            $sitting = $this->sittingKey($event, $party->first()->ticket_type_id);
            $free[$sitting] ??= $this->freeSeats($event, $party->first()->ticket_type_id, $tables);

            // The fullest table the whole party fits at.
            $fits = array_filter($free[$sitting], fn (int $n) => $n >= $party->count());
            asort($fits);
            $together = array_key_first($fits);

            foreach ($party as $ticket) {
                $tableId = $together;

                if ($tableId === null) {
                    // No table holds them all: the emptiest table, one by one.
                    $most = max($free[$sitting] ?: [0]);
                    $tableId = $most > 0 ? array_search($most, $free[$sitting], true) : null;
                }

                if ($tableId === null) {
                    break 2; // every table is full
                }

                $ticket->update(['seating_table_id' => $tableId]);
                $free[$sitting][$tableId]--;
                $assigned++;
            }
        }

        return $assigned;
    }

    /**
     * Seats left at each table for a ticket type's sitting — capacity less
     * live admissions and other pending orders' picks.
     *
     * @param  Collection<int, SeatingTable>|null  $tables
     * @return array<int, int> table id => seats left
     */
    public function freeSeats(Event $event, ?int $ticketTypeId, ?Collection $tables = null, ?int $exceptOrderId = null): array
    {
        $tables ??= $event->seatingTables()->orderBy('sort_order')->get(['id', 'capacity']);
        $perType = $event->tables_per_ticket_type && $ticketTypeId;

        $seated = Ticket::query()
            ->where('event_id', $event->id)
            ->whereIn('status', ['valid', 'checked_in'])
            ->whereNotNull('seating_table_id')
            ->when($perType, fn ($q) => $q->where('ticket_type_id', $ticketTypeId))
            ->groupBy('seating_table_id')
            ->selectRaw('seating_table_id, COUNT(*) as seats')
            ->pluck('seats', 'seating_table_id');

        $held = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.event_id', $event->id)
            ->where('orders.status', 'pending')
            ->whereNotNull('order_items.seating_table_id')
            ->when($exceptOrderId, fn ($q) => $q->where('orders.id', '!=', $exceptOrderId))
            ->when($perType, fn ($q) => $q->where('order_items.ticket_type_id', $ticketTypeId))
            ->groupBy('order_items.seating_table_id')
            ->selectRaw('order_items.seating_table_id as table_id, SUM(order_items.quantity) as seats')
            ->pluck('seats', 'table_id');

        return $tables->mapWithKeys(fn ($t) => [
            $t->id => max(0, (int) $t->capacity - (int) ($seated[$t->id] ?? 0) - (int) ($held[$t->id] ?? 0)),
        ])->all();
    }

    /**
     * Orders that have picked a table — paid, or pending and so holding it —
     * which is what "only the first N buyers choose" counts.
     */
    public function choicesUsed(Event $event, ?int $exceptOrderId = null): int
    {
        return Order::where('event_id', $event->id)
            ->whereIn('status', ['pending', 'paid'])
            ->when($exceptOrderId, fn ($q) => $q->whereKeyNot($exceptOrderId))
            ->whereHas('items', fn ($q) => $q->whereNotNull('seating_table_id'))
            ->count();
    }

    /** Whether this order's buyer may pick a table right now. */
    public function canChoose(Event $event, ?Order $order = null): bool
    {
        if ($event->ticketing_mode !== 'tables' || ! $event->table_choice || ! $event->seatingTables()->exists()) {
            return false;
        }

        // Someone who already holds a pick keeps theirs while they finish paying.
        if ($order && $order->items()->whereNotNull('seating_table_id')->exists()) {
            return true;
        }

        return $event->table_choice_limit === null || $this->choicesUsed($event, $order?->id) < $event->table_choice_limit;
    }

    /** How many more orders may still pick, or null when there is no limit. */
    public function choicesLeft(Event $event): ?int
    {
        return $event->table_choice_limit === null ? null : max(0, $event->table_choice_limit - $this->choicesUsed($event));
    }

    /**
     * What the checkout page needs to offer the pick: each ticket line of the
     * order and, for it, every table with the seats it has left. Null when the
     * buyer cannot pick.
     *
     * @return array<string, mixed>|null
     */
    public function options(Order $order): ?array
    {
        $event = $order->event;

        if (! $event || ! $this->canChoose($event, $order)) {
            return null;
        }

        $tables = $event->seatingTables()->orderBy('sort_order')->get();

        $lines = $order->items->map(function (OrderItem $item) use ($event, $tables, $order) {
            $free = $this->freeSeats($event, $item->ticket_type_id, $tables, $order->id);

            return [
                'item_id' => $item->id,
                'name' => $item->name,
                'quantity' => (int) $item->quantity,
                'chosen' => $item->seating_table_id,
                'tables' => $tables->map(fn (SeatingTable $t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'shape' => $t->shape,
                    'capacity' => (int) $t->capacity,
                    'left' => $free[$t->id] ?? 0,
                ])->values()->all(),
            ];
        })->values()->all();

        return [
            'lines' => $lines,
            'limit' => $event->table_choice_limit,
            // The rest are seated by the organizer, or straight away when auto-seat is on.
            'auto_seat' => (bool) $event->auto_assign_tables,
        ];
    }

    /**
     * Record the buyer's picks — order item id => table id, or null for "no
     * preference" — checking each table still has room for the whole line.
     * Locks the event's tables so two buyers cannot both take the last seats.
     *
     * @param  array<int|string, mixed>  $picks
     */
    public function choose(Order $order, array $picks): void
    {
        $event = $order->event;

        DB::transaction(function () use ($order, $event, $picks) {
            $tables = SeatingTable::where('event_id', $event->id)->orderBy('sort_order')->lockForUpdate()->get();
            $wantsAny = collect($picks)->filter(fn ($id) => $id !== null && $id !== '')->isNotEmpty();

            if ($wantsAny && ! $this->canChoose($event, $order)) {
                throw ValidationException::withMessages(['tables' => 'Table picks are all taken for this event — we’ll seat you.']);
            }

            foreach ($order->items as $item) {
                $tableId = $picks[$item->id] ?? $picks[(string) $item->id] ?? null;
                $tableId = $tableId === null || $tableId === '' ? null : (int) $tableId;

                if ($tableId === null) {
                    $item->update(['seating_table_id' => null]);

                    continue;
                }

                $table = $tables->firstWhere('id', $tableId);

                if (! $table) {
                    throw ValidationException::withMessages(["tables.{$item->id}" => 'That table is not at this event.']);
                }

                $left = $this->freeSeats($event, $item->ticket_type_id, $tables, $order->id)[$table->id] ?? 0;

                if ($left < $item->quantity) {
                    throw ValidationException::withMessages(["tables.{$item->id}" => $left > 0
                        ? "{$table->name} has only {$left} seat".($left === 1 ? '' : 's')." left — pick a table with room for all {$item->quantity}."
                        : "{$table->name} has just filled up — please pick another table."]);
                }

                $item->update(['seating_table_id' => $table->id]);
            }
        });
    }

    /** Tables are shared by every ticket type unless each type is its own sitting. */
    private function sittingKey(Event $event, ?int $ticketTypeId): string
    {
        return $event->tables_per_ticket_type ? 'type:'.(int) $ticketTypeId : 'all';
    }
}

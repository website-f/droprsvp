<?php

namespace App\Http\Controllers\Host;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Services\TableAssignmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SeatingController extends Controller
{
    public function index(Request $request, Event $event)
    {
        $this->authorize('update', $event);

        // Only live admissions take a seat; a refunded ticket keeps its table
        // id for the record but no longer sits there.
        $event->load(['seatingTables' => fn ($q) => $q->withCount(['tickets' => fn ($t) => $t->live()])]);

        $tickets = $event->tickets()
            ->whereIn('status', ['valid', 'checked_in'])
            ->with(['ticketType:id,name', 'order:id', 'order.items:id,order_id,ticket_type_id,seating_table_id'])
            ->orderBy('id')
            ->get();

        return inertia('host/events/seating', [
            'event' => [
                'title' => $event->title, 'slug' => $event->slug,
                'table_choice' => (bool) $event->table_choice,
                'table_choice_limit' => $event->table_choice_limit,
                'choices_used' => app(TableAssignmentService::class)->choicesUsed($event),
                'per_ticket_type' => (bool) $event->tables_per_ticket_type,
            ],
            'tables' => $event->seatingTables->map(fn ($t) => [
                'id' => $t->id, 'name' => $t->name, 'capacity' => $t->capacity, 'assigned' => $t->tickets_count,
                // Per sitting, when each ticket type fills the tables on its own.
                'by_type' => $event->tables_per_ticket_type
                    ? $tickets->where('seating_table_id', $t->id)->countBy('ticket_type_id')->all()
                    : null,
            ]),
            'tickets' => $tickets->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->attendee_name ?: 'Guest',
                'type' => $t->ticketType?->name,
                'type_id' => $t->ticket_type_id,
                'table_id' => $t->seating_table_id,
                // The buyer picked this table themselves at checkout.
                'chosen' => $t->seating_table_id !== null && (bool) $t->order?->items->first(
                    fn ($i) => $i->ticket_type_id === $t->ticket_type_id && $i->seating_table_id === $t->seating_table_id
                ),
            ]),
        ]);
    }

    /** Upsert the tables and drop removed ones (dropping a table unassigns its tickets via FK). */
    public function saveTables(Request $request, Event $event)
    {
        $this->authorize('update', $event);

        $data = $request->validate([
            'tables' => ['array'],
            'tables.*.id' => ['nullable', 'integer'],
            'tables.*.name' => ['required', 'string', 'max:80'],
            'tables.*.capacity' => ['required', 'integer', 'min:1', 'max:1000'],
        ]);

        $keep = [];
        foreach ($data['tables'] ?? [] as $i => $row) {
            $attrs = ['name' => $row['name'], 'capacity' => $row['capacity'], 'sort_order' => $i];
            $model = ! empty($row['id']) ? $event->seatingTables()->whereKey($row['id'])->first() : null;
            $model ? $model->update($attrs) : $model = $event->seatingTables()->create($attrs);
            $keep[] = $model->id;
        }
        $event->seatingTables()->whereKeyNot($keep)->delete();

        return back()->with('success', 'Tables saved.');
    }

    /** Auto-seat every unassigned admission across the tables (respecting capacity). */
    public function autoAssign(Request $request, Event $event, TableAssignmentService $svc)
    {
        $this->authorize('update', $event);

        $count = $svc->autoAssign($event);

        return back()->with('success', $count > 0
            ? "Auto-assigned {$count} attendee".($count === 1 ? '' : 's').' to tables.'
            : 'Nothing to assign — everyone is already seated, or the tables are full.');
    }

    /** Assign (or clear) a ticket's table, enforcing capacity (and buyers' held picks). */
    public function assign(Request $request, Event $event, TableAssignmentService $svc)
    {
        $this->authorize('update', $event);

        $data = $request->validate([
            'ticket_id' => ['required', 'integer'],
            'seating_table_id' => ['nullable', 'integer'],
        ]);

        $ticket = $event->tickets()->whereKey($data['ticket_id'])->firstOrFail();
        $tableId = $data['seating_table_id'] ?: null;

        if ($tableId === null) {
            $ticket->update(['seating_table_id' => null]);

            return back();
        }

        DB::transaction(function () use ($event, $tableId, $ticket, $svc) {
            $table = $event->seatingTables()->whereKey($tableId)->lockForUpdate()->firstOrFail();
            $free = $svc->freeSeats($event, $ticket->ticket_type_id, collect([$table]))[$table->id] ?? 0;

            // The ticket already sitting here is not taking a seat from itself.
            if ((int) $ticket->seating_table_id === $table->id) {
                $free++;
            }

            if ($free <= 0) {
                throw ValidationException::withMessages(['seating' => "“{$table->name}” is full."]);
            }
            $ticket->update(['seating_table_id' => $table->id]);
        });

        return back();
    }
}

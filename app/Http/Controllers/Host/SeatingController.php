<?php

namespace App\Http\Controllers\Host;

use App\Http\Controllers\Controller;
use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SeatingController extends Controller
{
    public function index(Request $request, Event $event)
    {
        $this->authorize('update', $event);

        $event->load(['seatingTables' => fn ($q) => $q->withCount('tickets')]);

        return inertia('host/events/seating', [
            'event' => ['title' => $event->title, 'slug' => $event->slug],
            'tables' => $event->seatingTables->map(fn ($t) => [
                'id' => $t->id, 'name' => $t->name, 'capacity' => $t->capacity, 'assigned' => $t->tickets_count,
            ]),
            'tickets' => $event->tickets()
                ->whereIn('status', ['valid', 'checked_in'])
                ->with('ticketType:id,name')
                ->orderBy('id')
                ->get()
                ->map(fn ($t) => [
                    'id' => $t->id,
                    'name' => $t->attendee_name ?: 'Guest',
                    'type' => $t->ticketType?->name,
                    'table_id' => $t->seating_table_id,
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
    public function autoAssign(Request $request, Event $event, \App\Services\TableAssignmentService $svc)
    {
        $this->authorize('update', $event);

        $count = $svc->autoAssign($event);

        return back()->with('success', $count > 0
            ? "Auto-assigned {$count} attendee".($count === 1 ? '' : 's').' to tables.'
            : 'Nothing to assign — everyone is already seated, or the tables are full.');
    }

    /** Assign (or clear) a ticket's table, enforcing capacity. */
    public function assign(Request $request, Event $event)
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

        DB::transaction(function () use ($event, $tableId, $ticket) {
            $table = $event->seatingTables()->whereKey($tableId)->lockForUpdate()->firstOrFail();
            $assigned = $table->tickets()->where('id', '!=', $ticket->id)->count();
            if ($assigned >= $table->capacity) {
                throw ValidationException::withMessages(['seating' => "“{$table->name}” is full."]);
            }
            $ticket->update(['seating_table_id' => $table->id]);
        });

        return back();
    }
}

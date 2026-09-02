<?php

namespace App\Http\Controllers\Host;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventCategory;
use App\Support\Cities;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EventController extends Controller
{
    /** The host's own events. */
    public function index(Request $request)
    {
        $events = $request->user()->events()
            ->withCount(['ticketTypes', 'sessions', 'orders'])
            ->latest()
            ->get();

        return inertia('host/events/index', ['events' => $events]);
    }

    public function create(Request $request)
    {
        return inertia('host/events/form', [
            'event' => null,
            'categories' => EventCategory::orderBy('name')->get(['id', 'name']),
            'cities' => Cities::all(),
            'seatTemplates' => \App\Models\SeatTemplate::where('user_id', $request->user()->id)
                ->orderByDesc('id')->get(['id', 'name', 'data']),
            'ticketingModes' => \App\Http\Controllers\Admin\SettingsController::ticketingModes(),
            'isSuperadmin' => (bool) $request->user()->hasRole('superadmin'),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['ticketing_mode'] = $this->resolveMode($request, $data);

        $event = new Event($this->eventAttributes($data));
        $event->user_id = $request->user()->id;
        $event->slug = $this->uniqueSlug($data['title']);
        $event->save();

        $this->syncSessions($event, $data['sessions'] ?? []);
        $this->syncTicketTypes($event, $data['ticketTypes'] ?? []);
        $this->syncSeatSections($event, $data['ticketing_mode'] === 'reserved' ? ($data['sections'] ?? []) : []);
        $this->syncTables($event, $data['ticketing_mode'] === 'tables' ? ($data['tables'] ?? []) : []);
        $this->flagPolicy($event);

        return redirect()->route('host.events.index')->with('success', 'Event created.');
    }

    public function edit(Request $request, Event $event)
    {
        $this->authorize('update', $event);

        $event->load(['sessions', 'ticketTypes' => fn ($q) => $q->whereNull('seat_section_id'), 'seatSections' => fn ($q) => $q->withCount('seats'), 'seatingTables' => fn ($q) => $q->orderBy('sort_order')]);

        return inertia('host/events/form', [
            'event' => $event,
            'categories' => EventCategory::orderBy('name')->get(['id', 'name']),
            'cities' => Cities::all(),
            'seatTemplates' => \App\Models\SeatTemplate::where('user_id', $request->user()->id)
                ->orderByDesc('id')->get(['id', 'name', 'data']),
            'ticketingModes' => \App\Http\Controllers\Admin\SettingsController::ticketingModes(),
            'isSuperadmin' => (bool) $request->user()->hasRole('superadmin'),
        ]);
    }

    public function update(Request $request, Event $event)
    {
        $this->authorize('update', $event);

        $data = $this->validated($request);
        $data['ticketing_mode'] = $this->resolveMode($request, $data);
        $event->fill($this->eventAttributes($data))->save();

        $this->syncSessions($event, $data['sessions'] ?? []);
        $this->syncTicketTypes($event, $data['ticketTypes'] ?? []);
        $this->syncSeatSections($event, $data['ticketing_mode'] === 'reserved' ? ($data['sections'] ?? []) : []);
        $this->syncTables($event, $data['ticketing_mode'] === 'tables' ? ($data['tables'] ?? []) : []);
        $this->flagPolicy($event);

        return redirect()->route('host.events.index')->with('success', 'Event updated.');
    }

    public function destroy(Request $request, Event $event)
    {
        $this->authorize('delete', $event);
        $event->delete();

        return redirect()->route('host.events.index')->with('success', 'Event deleted.');
    }

    /** Appeal an admin cancellation with a reason + proof attachments (organizer). */
    public function reappeal(Request $request, Event $event)
    {
        $this->authorize('update', $event);
        abort_unless($event->status === 'cancelled', 422);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
            'attachments' => ['required', 'array', 'min:1', 'max:6'],
            'attachments.*' => ['required', 'string', 'max:2048'],
        ]);

        $event->update([
            'appeal_status' => 'pending',
            'appeal_reason' => $data['reason'],
            'appeal_attachments' => array_values($data['attachments']),
            'appealed_at' => now(),
        ]);

        \App\Models\AppNotification::notifyMany(\App\Models\User::role('superadmin')->pluck('id'), [
            'type' => 'event',
            'title' => 'Event cancellation appeal',
            'body' => "{$request->user()->name} appealed the cancellation of “{$event->title}”.",
            'url' => route('admin.events.show', $event->slug, false),
            'level' => 'warning',
        ]);

        return back()->with('success', 'Appeal submitted — our team will review it.');
    }

    /**
     * Flag an event whose text matches a policy keyword and notify admins to review.
     * Keywords are a comma-separated superadmin setting (with a sensible default).
     */
    private function flagPolicy(Event $event): void
    {
        $keywords = array_filter(array_map('trim', explode(',', (string) \App\Models\Setting::get(
            'policy_keywords', 'weapon,firearm,drugs,gambling,counterfeit,scam,escort,nude'
        ))));
        if (empty($keywords)) {
            return;
        }

        $haystack = strtolower(trim(($event->title ?? '').' '.($event->subtitle ?? '').' '.strip_tags((string) $event->description)));
        $hit = collect($keywords)->first(fn ($k) => $k !== '' && str_contains($haystack, strtolower($k)));
        if (! $hit) {
            return;
        }

        \App\Models\AppNotification::notifyMany(\App\Models\User::role('superadmin')->pluck('id'), [
            'type' => 'policy',
            'title' => 'Event may need review',
            'body' => "“{$event->title}” matched a policy keyword (“{$hit}”). Please review.",
            'url' => route('admin.events.show', $event->slug, false),
            'level' => 'warning',
        ]);
    }

    // ---- helpers -----------------------------------------------------------

    /**
     * The event's ticketing mode, forced to 'general' when the chosen mode has been
     * disabled platform-wide (superadmins bypass the gate and may use any mode).
     */
    private function resolveMode(Request $request, array $data): string
    {
        // Fall back to the legacy seating_enabled flag when no explicit mode is sent.
        $mode = $data['ticketing_mode'] ?? (($data['seating_enabled'] ?? false) ? 'reserved' : 'general');
        if (! in_array($mode, ['general', 'reserved', 'tables'], true)) {
            $mode = 'general';
        }

        $allowed = \App\Http\Controllers\Admin\SettingsController::ticketingModes();
        if (! $request->user()->hasRole('superadmin') && ! ($allowed[$mode] ?? false)) {
            $mode = 'general';
        }

        return $mode;
    }

    /** Upsert the event's banquet tables and drop any the host removed. */
    private function syncTables(Event $event, array $rows): void
    {
        $keep = [];
        foreach (array_values($rows) as $i => $row) {
            $attrs = [
                'name' => $row['name'],
                'shape' => in_array($row['shape'] ?? 'round', ['round', 'rect'], true) ? $row['shape'] : 'round',
                'capacity' => max(1, (int) $row['capacity']),
                'pos_x' => (int) ($row['pos_x'] ?? 0),
                'pos_y' => (int) ($row['pos_y'] ?? 0),
                'sort_order' => $i,
            ];
            $model = ! empty($row['id']) ? $event->seatingTables()->whereKey($row['id'])->first() : null;
            $model ? $model->update($attrs) : $model = $event->seatingTables()->create($attrs);
            $keep[] = $model->id;
        }
        // Dropping a removed table unassigns its tickets via the seating_table_id FK.
        $event->seatingTables()->whereKeyNot($keep)->delete();
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'subtitle' => ['nullable', 'string', 'max:200'],
            'category_id' => ['nullable', 'exists:event_categories,id'],
            'description' => ['nullable', 'string'],
            'cover_image' => ['nullable', 'string', 'max:2048'],
            'gallery' => ['array', 'max:12'],
            'gallery.*' => ['string', 'max:2048'],
            'show_participants' => ['boolean'],
            'show_reviews' => ['boolean'],
            'seating_enabled' => ['boolean'],
            'ticketing_mode' => ['nullable', 'in:general,reserved,tables'],
            'auto_assign_tables' => ['boolean'],
            'tables' => ['array'],
            'tables.*.id' => ['nullable', 'integer'],
            'tables.*.name' => ['required', 'string', 'max:80'],
            'tables.*.shape' => ['nullable', 'in:round,rect'],
            'tables.*.capacity' => ['required', 'integer', 'min:1', 'max:1000'],
            'tables.*.pos_x' => ['nullable', 'integer'],
            'tables.*.pos_y' => ['nullable', 'integer'],
            'sections' => ['array'],
            'sections.*.id' => ['nullable', 'integer'],
            'sections.*.name' => ['required', 'string', 'max:120'],
            'sections.*.color' => ['nullable', 'string', 'max:20'],
            'sections.*.kind' => ['required', 'in:seated,ga,stage'],
            'sections.*.price' => ['nullable', 'numeric', 'min:0'],
            'sections.*.rows' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sections.*.cols' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sections.*.capacity' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'sections.*.x' => ['nullable', 'integer'],
            'sections.*.y' => ['nullable', 'integer'],
            'sections.*.width' => ['nullable', 'integer', 'min:20', 'max:2000'],
            'sections.*.height' => ['nullable', 'integer', 'min:20', 'max:2000'],
            'sections.*.row_label_start' => ['nullable', 'string', 'max:4'],
            'sections.*.curve' => ['nullable', 'integer', 'min:0', 'max:100'],
            'visibility' => ['required', 'in:public,unlisted,private'],
            'timezone' => ['required', 'string', 'max:64'],
            'is_online' => ['boolean'],
            'venue_name' => ['nullable', 'string', 'max:180'],
            'venue_address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:80'],
            'online_url' => ['nullable', 'url', 'max:2048'],
            'capacity' => ['nullable', 'integer', 'min:0'],
            'publish' => ['boolean'],

            'sessions' => ['array'],
            'sessions.*.id' => ['nullable', 'integer'],
            'sessions.*.title' => ['nullable', 'string', 'max:120'],
            'sessions.*.starts_at' => ['required', 'date'],
            'sessions.*.ends_at' => ['nullable', 'date', 'after_or_equal:sessions.*.starts_at'],
            'sessions.*.capacity' => ['nullable', 'integer', 'min:0'],

            'ticketTypes' => ['array'],
            'ticketTypes.*.id' => ['nullable', 'integer'],
            'ticketTypes.*.name' => ['required', 'string', 'max:120'],
            'ticketTypes.*.description' => ['nullable', 'string', 'max:255'],
            'ticketTypes.*.kind' => ['required', 'in:paid,free,donation'],
            'ticketTypes.*.price' => ['required', 'numeric', 'min:0'],
            'ticketTypes.*.compare_at_price' => ['nullable', 'numeric', 'min:0'],
            'ticketTypes.*.quantity' => ['nullable', 'integer', 'min:0'],
            'ticketTypes.*.min_per_order' => ['required', 'integer', 'min:1'],
            'ticketTypes.*.max_per_order' => ['required', 'integer', 'min:1'],
            'ticketTypes.*.sales_start' => ['nullable', 'date'],
            'ticketTypes.*.sales_end' => ['nullable', 'date', 'after_or_equal:ticketTypes.*.sales_start'],
            'ticketTypes.*.is_active' => ['boolean'],
        ]);
    }

    /** Map validated input to the event's own columns (status derived from `publish`). */
    private function eventAttributes(array $data): array
    {
        return [
            'title' => $data['title'],
            'subtitle' => $data['subtitle'] ?? null,
            'category_id' => $data['category_id'] ?? null,
            'description' => $data['description'] ?? null,
            'cover_image' => $data['cover_image'] ?? null,
            'gallery' => $data['gallery'] ?? [],
            'show_participants' => $data['show_participants'] ?? true,
            'show_reviews' => $data['show_reviews'] ?? true,
            // seating_enabled is derived from the mode so the existing reserved-seating
            // checkout/rendering keeps working unchanged.
            'ticketing_mode' => $data['ticketing_mode'] ?? 'general',
            'seating_enabled' => ($data['ticketing_mode'] ?? 'general') === 'reserved',
            'auto_assign_tables' => ($data['ticketing_mode'] ?? 'general') === 'tables' ? ($data['auto_assign_tables'] ?? false) : false,
            'visibility' => $data['visibility'],
            'timezone' => $data['timezone'],
            'is_online' => $data['is_online'] ?? false,
            'venue_name' => $data['venue_name'] ?? null,
            'venue_address' => $data['venue_address'] ?? null,
            'city' => ($data['is_online'] ?? false) ? null : ($data['city'] ?? null),
            'online_url' => $data['online_url'] ?? null,
            'capacity' => $data['capacity'] ?? null,
            'status' => ($data['publish'] ?? false) ? 'published' : 'draft',
            'published_at' => ($data['publish'] ?? false) ? now() : null,
            'starts_at' => collect($data['sessions'] ?? [])->min('starts_at'),
            'ends_at' => collect($data['sessions'] ?? [])->max('ends_at'),
        ];
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'event';
        $slug = $base;
        $i = 2;
        while (Event::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    /** Upsert the sessions in the payload and delete any the host removed. */
    private function syncSessions(Event $event, array $rows): void
    {
        $keep = [];
        foreach ($rows as $i => $row) {
            $attrs = [
                'title' => $row['title'] ?? null,
                'starts_at' => $row['starts_at'],
                'ends_at' => $row['ends_at'] ?? null,
                'capacity' => $row['capacity'] ?? null,
                'sort_order' => $i,
            ];
            $model = ! empty($row['id'])
                ? $event->sessions()->whereKey($row['id'])->first()
                : null;
            $model ? $model->update($attrs) : $model = $event->sessions()->create($attrs);
            $keep[] = $model->id;
        }
        $event->sessions()->whereKeyNot($keep)->delete();
    }

    /** Upsert ticket types and delete removed ones (safe pre-sales in P1). */
    private function syncTicketTypes(Event $event, array $rows): void
    {
        $keep = [];
        foreach ($rows as $i => $row) {
            $attrs = [
                'name' => $row['name'],
                'description' => $row['description'] ?? null,
                'kind' => $row['kind'],
                'price' => $row['kind'] === 'free' ? 0 : $row['price'],
                // Normal price only counts when it's higher than the selling price.
                'compare_at_price' => ($row['kind'] === 'paid' && ! empty($row['compare_at_price']) && (float) $row['compare_at_price'] > (float) $row['price'])
                    ? $row['compare_at_price'] : null,
                'quantity' => $row['quantity'] ?? null,
                'min_per_order' => $row['min_per_order'],
                'max_per_order' => $row['max_per_order'],
                'sales_start' => $row['sales_start'] ?? null,
                'sales_end' => $row['sales_end'] ?? null,
                'is_active' => $row['is_active'] ?? true,
                'sort_order' => $i,
            ];
            $model = ! empty($row['id'])
                ? $event->ticketTypes()->whereKey($row['id'])->first()
                : null;
            $model ? $model->update($attrs) : $model = $event->ticketTypes()->create($attrs);
            $keep[] = $model->id;
        }
        // Only prune MANUAL ticket types — seat-section-backed ones are managed by syncSeatSections.
        $event->ticketTypes()->whereNull('seat_section_id')->whereKeyNot($keep)->delete();
    }

    /**
     * Upsert reserved-seating sections. Each section is backed by a ticket type
     * (so pricing/inventory/refunds reuse the existing machinery) and, when
     * "seated", owns a generated grid of individual seats.
     */
    private function syncSeatSections(Event $event, array $rows): void
    {
        $keep = [];
        foreach (array_values($rows) as $i => $row) {
            $kind = in_array($row['kind'] ?? 'seated', ['ga', 'stage'], true) ? $row['kind'] : 'seated';
            $price = $kind === 'stage' ? 0.0 : (float) ($row['price'] ?? 0);
            $rowsN = $kind === 'seated' ? max(1, (int) ($row['rows'] ?? 1)) : null;
            $colsN = $kind === 'seated' ? max(1, (int) ($row['cols'] ?? 1)) : null;
            $capacity = $kind === 'ga' ? max(1, (int) ($row['capacity'] ?? 1)) : null;
            $qty = $kind === 'seated' ? $rowsN * $colsN : $capacity;
            $start = strtoupper(substr((string) ($row['row_label_start'] ?? 'A'), 0, 1)) ?: 'A';

            $section = ! empty($row['id']) ? $event->seatSections()->whereKey($row['id'])->first() : null;
            $attrs = [
                'name' => $row['name'], 'color' => $row['color'] ?? '#6c63ff', 'kind' => $kind,
                'price' => $price, 'currency' => 'MYR',
                'rows' => $rowsN, 'cols' => $colsN, 'capacity' => $capacity, 'sort_order' => $i,
                'x' => (int) ($row['x'] ?? 20), 'y' => (int) ($row['y'] ?? 20),
                'width' => isset($row['width']) ? (int) $row['width'] : null,
                'height' => isset($row['height']) ? (int) $row['height'] : null,
                'row_label_start' => $start,
                'curve' => $kind === 'seated' ? max(0, min(100, (int) ($row['curve'] ?? 0))) : 0,
            ];
            $section ? $section->update($attrs) : $section = $event->seatSections()->create($attrs);

            // A stage is a layout marker — no ticket type, no seats.
            if ($kind === 'stage') {
                $section->ticketType?->delete();
                $section->update(['ticket_type_id' => null]);
                $section->seats()->where('status', 'available')->delete();
                $keep[] = $section->id;
                continue;
            }

            $ttAttrs = [
                'seat_section_id' => $section->id, 'name' => $section->name,
                'kind' => $price > 0 ? 'paid' : 'free', 'price' => $price, 'currency' => 'MYR',
                'quantity' => $qty, 'min_per_order' => 1, 'max_per_order' => max(1, min(20, (int) $qty)),
                'is_active' => true, 'sort_order' => $i,
            ];
            if ($section->ticketType) {
                $section->ticketType->update($ttAttrs);
            } else {
                $tt = $event->ticketTypes()->create($ttAttrs);
                $section->update(['ticket_type_id' => $tt->id]);
            }

            if ($kind === 'seated') {
                $this->syncSeats($section->fresh(), $rowsN, $colsN, $start);
            } else {
                $section->seats()->where('status', 'available')->delete();
            }

            $keep[] = $section->id;
        }

        foreach ($event->seatSections()->whereKeyNot($keep)->get() as $stale) {
            $stale->ticketType?->delete();
            $stale->delete(); // cascades its seats
        }
    }

    /** Rebuild a seated section's grid, never touching seats that are held/sold. */
    private function syncSeats(\App\Models\SeatSection $section, int $rows, int $cols, string $start = 'A'): void
    {
        $offset = max(0, ord($start) - 65);
        $existing = $section->seats()->get()->keyBy('label');
        $keep = [];
        $order = 0;
        for ($r = 0; $r < $rows; $r++) {
            $rowLabel = $this->rowLabel($offset + $r);
            for ($c = 1; $c <= $cols; $c++) {
                $label = $rowLabel.$c;
                if ($seat = $existing->get($label)) {
                    $seat->update(['row_label' => $rowLabel, 'number' => $c, 'sort_order' => $order]);
                } else {
                    $section->seats()->create([
                        'event_id' => $section->event_id, 'row_label' => $rowLabel, 'number' => $c,
                        'label' => $label, 'status' => 'available', 'sort_order' => $order,
                    ]);
                }
                $keep[] = $label;
                $order++;
            }
        }
        $section->seats()->whereNotIn('label', $keep)->where('status', 'available')->delete();
    }

    /** 0→A, 25→Z, 26→AA … for row labels. */
    private function rowLabel(int $i): string
    {
        $label = '';
        $i++;
        while ($i > 0) {
            $i--;
            $label = chr(65 + ($i % 26)).$label;
            $i = intdiv($i, 26);
        }

        return $label;
    }
}

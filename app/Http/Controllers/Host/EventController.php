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

    public function create()
    {
        return inertia('host/events/form', [
            'event' => null,
            'categories' => EventCategory::orderBy('name')->get(['id', 'name']),
            'cities' => Cities::all(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $event = new Event($this->eventAttributes($data));
        $event->user_id = $request->user()->id;
        $event->slug = $this->uniqueSlug($data['title']);
        $event->save();

        $this->syncSessions($event, $data['sessions'] ?? []);
        $this->syncTicketTypes($event, $data['ticketTypes'] ?? []);

        return redirect()->route('host.events.index')->with('success', 'Event created.');
    }

    public function edit(Request $request, Event $event)
    {
        $this->authorize('update', $event);

        $event->load(['sessions', 'ticketTypes']);

        return inertia('host/events/form', [
            'event' => $event,
            'categories' => EventCategory::orderBy('name')->get(['id', 'name']),
            'cities' => Cities::all(),
        ]);
    }

    public function update(Request $request, Event $event)
    {
        $this->authorize('update', $event);

        $data = $this->validated($request);
        $event->fill($this->eventAttributes($data))->save();

        $this->syncSessions($event, $data['sessions'] ?? []);
        $this->syncTicketTypes($event, $data['ticketTypes'] ?? []);

        return redirect()->route('host.events.index')->with('success', 'Event updated.');
    }

    public function destroy(Request $request, Event $event)
    {
        $this->authorize('delete', $event);
        $event->delete();

        return redirect()->route('host.events.index')->with('success', 'Event deleted.');
    }

    // ---- helpers -----------------------------------------------------------

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
        $event->ticketTypes()->whereKeyNot($keep)->delete();
    }
}

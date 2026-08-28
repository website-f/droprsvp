<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use Illuminate\Http\Request;

class EventsController extends Controller
{
    /** Superadmin — every event across all organizers. */
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        $events = Event::with('user:id,name')
            ->withCount(['tickets as sold' => fn ($t) => $t->whereIn('status', ['valid', 'checked_in'])])
            ->withSum(['orders as revenue' => fn ($o) => $o->where('status', 'paid')], 'total')
            ->when($q !== '', fn ($query) => $query->where('title', 'like', "%{$q}%"))
            ->latest()
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Event $e) => [
                'title' => $e->title,
                'slug' => $e->slug,
                'organizer' => $e->user?->name,
                'status' => $e->status,
                'sold' => $e->sold,
                'revenue' => (float) ($e->revenue ?? 0),
            ]);

        return inertia('admin/events/index', ['events' => $events, 'filters' => ['q' => $q]]);
    }

    /** Superadmin — full detail of a single event for review/moderation. */
    public function show(Event $event)
    {
        $event->load(['user:id,name,email', 'category:id,name', 'sessions', 'ticketTypes']);
        $sold = $event->tickets()->whereIn('status', ['valid', 'checked_in'])->count();
        $revenue = (float) $event->orders()->where('status', 'paid')->sum('total');

        return inertia('admin/events/show', [
            'event' => [
                'slug' => $event->slug,
                'title' => $event->title,
                'subtitle' => $event->subtitle,
                'description' => $event->description,
                'cover_image' => $event->cover_image,
                'status' => $event->status,
                'cancelled_reason' => $event->cancelled_reason,
                'visibility' => $event->visibility,
                'category' => $event->category?->name,
                'city' => $event->city,
                'is_online' => $event->is_online,
                'venue_name' => $event->venue_name,
                'venue_address' => $event->venue_address,
                'when' => $event->starts_at?->copy()->setTimezone($event->timezone)->format('D, j M Y · g:i A'),
                'organizer' => ['name' => $event->user?->name, 'email' => $event->user?->email],
                'sold' => $sold,
                'revenue' => $revenue,
                'ticket_types' => $event->ticketTypes->map(fn ($t) => [
                    'name' => $t->name, 'kind' => $t->kind, 'price' => (float) $t->price, 'quantity' => $t->quantity,
                ]),
            ],
        ]);
    }

    /** Cancel an event that breaches policy (superadmin only). */
    public function cancel(Request $request, Event $event)
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        $event->update(['status' => 'cancelled', 'cancelled_reason' => $data['reason'] ?? null]);

        return redirect()->route('admin.events.show', $event->slug)->with('success', 'Event cancelled.');
    }

    /** Restore a cancelled event back to draft so the organizer can re-review it. */
    public function restore(Event $event)
    {
        $event->update(['status' => 'draft', 'cancelled_reason' => null, 'published_at' => null]);

        return redirect()->route('admin.events.show', $event->slug)->with('success', 'Event restored to draft.');
    }
}

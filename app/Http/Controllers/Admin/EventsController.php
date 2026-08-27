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
}

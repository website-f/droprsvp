<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventCategory;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DiscoverController extends Controller
{
    /** Public event discovery — search + category filter over published events (SSR). */
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $category = trim((string) $request->query('category', ''));

        $events = Event::published()
            ->with(['category:id,name,slug', 'ticketTypes:id,event_id,kind,price,is_active'])
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('title', 'like', "%{$q}%")
                ->orWhere('subtitle', 'like', "%{$q}%")
                ->orWhere('description', 'like', "%{$q}%")))
            ->when($category !== '', fn ($query) => $query->whereHas('category', fn ($c) => $c->where('slug', $category)))
            // Upcoming first (undated events still show).
            ->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '>=', now()->startOfDay()))
            ->orderByRaw('starts_at is null, starts_at asc')
            ->paginate(12)
            ->withQueryString()
            ->through(fn (Event $e) => $this->card($e));

        return Inertia::render('public/events/index', [
            'events' => $events,
            'categories' => EventCategory::orderBy('sort_order')->orderBy('name')->get(['name', 'slug']),
            'filters' => ['q' => $q, 'category' => $category],
            'seo' => [
                'title' => $q !== '' ? "Events matching “{$q}” · DropRSVP" : 'Browse events · DropRSVP',
                'description' => 'Discover events happening near you and get tickets on DropRSVP.',
                'canonical' => url('/events'),
            ],
        ]);
    }

    private function card(Event $event): array
    {
        $active = $event->ticketTypes->where('is_active', true);
        $paid = $active->where('kind', 'paid')->pluck('price')->map(fn ($p) => (float) $p);

        return [
            'slug' => $event->slug,
            'title' => $event->title,
            'cover_image' => $event->cover_image,
            'category' => $event->category?->name,
            'when' => optional($event->starts_at)?->setTimezone($event->timezone)->format('D, j M Y'),
            'venue' => $event->is_online ? 'Online' : $event->venue_name,
            'from_price' => $paid->isNotEmpty() ? $paid->min() : null,
            'has_free' => $active->whereIn('kind', ['free', 'donation'])->isNotEmpty(),
        ];
    }
}

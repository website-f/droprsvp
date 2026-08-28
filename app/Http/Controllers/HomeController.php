<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventCategory;
use App\Models\User;
use App\Support\SeoManager;
use App\Support\SiteContent;
use Inertia\Inertia;

class HomeController extends Controller
{
    /** The marketing landing page — featured upcoming events + category tiles. */
    public function index()
    {
        $featured = Event::published()
            ->with(['category:id,name,slug', 'ticketTypes:id,event_id,kind,price,is_active'])
            ->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '>=', now()->startOfDay()))
            ->orderByRaw('starts_at is null, starts_at asc')
            ->limit(3)
            ->get()
            ->map(fn (Event $e) => $this->card($e))
            ->values();

        // Homepage SEO is superadmin-editable (title/description/keywords only).
        $home = SiteContent::homeSeo();
        app(SeoManager::class)
            ->title($home['title'], false)
            ->description($home['description'])
            ->keywords($home['keywords'] ?: null)
            ->canonical(url('/'))
            ->type('website')
            ->schema([
                '@type' => 'ItemList',
                'name' => 'Featured events',
                'itemListElement' => $featured->map(fn ($e, $i) => [
                    '@type' => 'ListItem',
                    'position' => $i + 1,
                    'url' => url('/e/'.$e['slug']),
                    'name' => $e['title'],
                ])->all(),
            ]);

        return Inertia::render('welcome', [
            'featured' => $featured,
            'categories' => EventCategory::orderBy('sort_order')->orderBy('name')->get(['name', 'slug']),
            'sections' => SiteContent::landing(),
            'organizers' => $this->featuredOrganizers(),
        ]);
    }

    /** Top organizers by number of published events, with their soonest event. */
    private function featuredOrganizers(): \Illuminate\Support\Collection
    {
        return User::query()
            ->whereHas('events', fn ($q) => $q->published())
            ->withCount(['events as events_count' => fn ($q) => $q->published()])
            ->orderByDesc('events_count')
            ->limit(6)
            ->get()
            ->map(function (User $u) {
                $next = $u->events()->published()
                    ->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '>=', now()->startOfDay()))
                    ->orderByRaw('starts_at is null, starts_at asc')
                    ->first(['slug']);

                return [
                    'name' => $u->name,
                    'events_count' => $u->events_count,
                    'next_slug' => $next?->slug,
                ];
            })
            ->values();
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

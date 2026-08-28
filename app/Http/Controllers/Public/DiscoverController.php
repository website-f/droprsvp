<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventCategory;
use App\Support\SeoManager;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DiscoverController extends Controller
{
    /** Public event discovery — search + category filter over published events (SSR). */
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $category = trim((string) $request->query('category', ''));
        $when = trim((string) $request->query('when', ''));
        [$from, $to] = $this->whenRange($when);

        $events = Event::published()
            ->with(['category:id,name,slug', 'ticketTypes:id,event_id,kind,price,is_active'])
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('title', 'like', "%{$q}%")
                ->orWhere('subtitle', 'like', "%{$q}%")
                ->orWhere('description', 'like', "%{$q}%")
                ->orWhere('venue_name', 'like', "%{$q}%")))
            ->when($category !== '', fn ($query) => $query->whereHas('category', fn ($c) => $c->where('slug', $category)))
            ->when($from && $to, fn ($query) => $query->whereBetween('starts_at', [$from, $to]))
            // Upcoming first (undated events still show).
            ->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '>=', now()->startOfDay()))
            ->orderByRaw('starts_at is null, starts_at asc')
            ->paginate(12)
            ->withQueryString()
            ->through(fn (Event $e) => $this->card($e));

        $site = config('seo.site_name', 'DropRSVP');
        $title = $q !== '' ? "Events matching “{$q}”" : 'Browse events';
        // A filtered/search result view shouldn't compete with the canonical listing.
        $canonical = url('/events');

        $manager = app(SeoManager::class)
            ->title($title)
            ->description("Discover events happening near you and get tickets on {$site}.")
            ->canonical($canonical)
            ->type('website')
            ->schema([
                '@type' => 'CollectionPage',
                'name' => "{$title} · {$site}",
                'url' => $canonical,
                'isPartOf' => ['@id' => url('/#website')],
                'mainEntity' => [
                    '@type' => 'ItemList',
                    'itemListElement' => $events->getCollection()->map(fn ($e, $i) => [
                        '@type' => 'ListItem',
                        'position' => $i + 1,
                        'url' => url('/e/'.$e['slug']),
                        'name' => $e['title'],
                    ])->values()->all(),
                ],
            ])
            ->breadcrumb([
                ['name' => 'Home', 'url' => url('/')],
                ['name' => 'Events', 'url' => url('/events')],
            ]);
        if ($q !== '' || $category !== '' || $when !== '') {
            $manager->noindex(); // keep search/filter permutations out of the index
        }

        return Inertia::render('public/events/index', [
            'events' => $events,
            'categories' => EventCategory::orderBy('sort_order')->orderBy('name')->get(['name', 'slug']),
            'filters' => ['q' => $q, 'category' => $category, 'when' => $when],
            'seo' => ['title' => $title],
        ]);
    }

    /** Resolve a "when" chip into a [from, to] datetime range (or [null, null]). */
    private function whenRange(string $when): array
    {
        switch ($when) {
            case 'today':
                return [now()->startOfDay(), now()->endOfDay()];
            case 'weekend':
                // This weekend: from Saturday (or now, if it's already the weekend) to Sunday night.
                $start = now()->isWeekend() ? now() : now()->next(\Carbon\Carbon::SATURDAY)->startOfDay();
                $end = $start->copy()->next(\Carbon\Carbon::SUNDAY)->endOfDay();
                if ($start->isSunday()) {
                    $end = $start->copy()->endOfDay();
                }

                return [$start, $end];
            case 'week':
                return [now(), now()->endOfWeek()];
            case 'month':
                return [now(), now()->endOfMonth()];
            default:
                return [null, null];
        }
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

<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventCategory;
use App\Support\Cities;
use App\Support\SeoManager;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DiscoverController extends Controller
{
    /** The only locale for now — path-prefixed for SEO + future i18n. */
    public const LOCALE = 'en-my';

    /**
     * Public event discovery with SEO-friendly path URLs:
     *   /en-my                         all events
     *   /en-my/{city}                  events in a city
     *   /en-my/all/{category}          a category, any city
     *   /en-my/{city}/{category}       a city + category
     *
     * Free-text search (?q=) and time (?when=) stay as query refinements and are
     * kept out of the index.
     */
    public function index(Request $request, string $locale, ?string $city = null, ?string $category = null)
    {
        // A two-segment URL (/en-my/{seg}) may be a city OR a category — resolve it.
        if ($category === null && $city !== null && ! Cities::isKnownSlug($city)) {
            if (EventCategory::where('slug', $city)->exists()) {
                [$city, $category] = [Cities::ANY, $city];
            } else {
                abort(404);
            }
        }

        $citySlug = $city ?: Cities::ANY;
        $cityName = Cities::nameForSlug($citySlug);          // null for "all"
        abort_if($city !== null && $city !== Cities::ANY && $cityName === null, 404);

        $categoryModel = $category ? EventCategory::where('slug', $category)->first() : null;
        abort_if($category !== null && ! $categoryModel, 404);

        $q = trim((string) $request->query('q', ''));
        $when = trim((string) $request->query('when', ''));
        [$from, $to] = $this->whenRange($when);

        $events = Event::published()
            ->with(['category:id,name,slug', 'ticketTypes:id,event_id,kind,price,is_active'])
            ->when($cityName, fn ($query) => $query->where('city', $cityName))
            ->when($categoryModel, fn ($query) => $query->where('category_id', $categoryModel->id))
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('title', 'like', "%{$q}%")
                ->orWhere('subtitle', 'like', "%{$q}%")
                ->orWhere('description', 'like', "%{$q}%")
                ->orWhere('venue_name', 'like', "%{$q}%")))
            ->when($from && $to, fn ($query) => $query->whereBetween('starts_at', [$from, $to]))
            ->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '>=', now()->startOfDay()))
            ->orderByRaw('starts_at is null, starts_at asc')
            ->paginate(12)
            ->withQueryString()
            ->through(fn (Event $e) => $this->card($e));

        $site = config('seo.site_name', 'DropRSVP');
        $catName = $categoryModel?->name;
        $title = $this->heading($catName, $cityName, $q);
        $canonical = $this->pathUrl($cityName ? $citySlug : null, $categoryModel?->slug);

        $manager = app(SeoManager::class)
            ->title($title)
            ->description($this->metaDescription($catName, $cityName, $site))
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
            ->breadcrumb($this->breadcrumb($cityName, $citySlug, $categoryModel));

        // City/category pages ARE indexable (the whole point); only free-text
        // search results are kept out of the index.
        if ($q !== '') {
            $manager->noindex();
        }

        return Inertia::render('public/events/index', [
            'events' => $events,
            'categories' => EventCategory::orderBy('sort_order')->orderBy('name')->get(['name', 'slug']),
            'cities' => Cities::all(),
            'active' => [
                'city' => $cityName ? $citySlug : null,
                'city_name' => $cityName,
                'category' => $categoryModel?->slug,
                'category_name' => $catName,
            ],
            'filters' => ['q' => $q, 'when' => $when],
            'seo' => ['title' => $title],
        ]);
    }

    /** Legacy /events?category=&q= → 301 to the canonical path URL. */
    public function legacyRedirect(Request $request)
    {
        $citySlug = Cities::ANY;
        $catSlug = trim((string) $request->query('category', '')) ?: null;
        $qs = array_filter([
            'q' => trim((string) $request->query('q', '')),
            'when' => trim((string) $request->query('when', '')),
        ]);

        $url = $this->pathUrl($catSlug ? $citySlug : null, $catSlug);

        return redirect($url.($qs ? '?'.http_build_query($qs) : ''), 301);
    }

    /** Build a discovery path URL, omitting the city slot only when no category is present. */
    private function pathUrl(?string $citySlug, ?string $catSlug): string
    {
        $segments = [self::LOCALE];
        if ($catSlug) {
            $segments[] = $citySlug ?: Cities::ANY;
            $segments[] = $catSlug;
        } elseif ($citySlug) {
            $segments[] = $citySlug;
        }

        return url('/'.implode('/', $segments));
    }

    private function heading(?string $catName, ?string $cityName, string $q): string
    {
        if ($q !== '') {
            return "Events matching “{$q}”";
        }
        if ($catName && $cityName) {
            return "{$catName} events in {$cityName}";
        }
        if ($cityName) {
            return "Events in {$cityName}";
        }
        if ($catName) {
            return "{$catName} events";
        }

        return 'Browse events';
    }

    private function metaDescription(?string $catName, ?string $cityName, string $site): string
    {
        $what = $catName ? strtolower($catName).' events' : 'events';
        $where = $cityName ? " in {$cityName}" : ' near you';

        return "Discover {$what}{$where} and get tickets on {$site}.";
    }

    private function breadcrumb(?string $cityName, string $citySlug, ?EventCategory $category): array
    {
        $crumbs = [
            ['name' => 'Home', 'url' => url('/')],
            ['name' => 'Events', 'url' => $this->pathUrl(null, null)],
        ];
        if ($cityName) {
            $crumbs[] = ['name' => $cityName, 'url' => $this->pathUrl($citySlug, null)];
        }
        if ($category) {
            $crumbs[] = ['name' => $category->name, 'url' => $this->pathUrl($cityName ? $citySlug : null, $category->slug)];
        }

        return $crumbs;
    }

    /** Resolve a "when" chip into a [from, to] datetime range (or [null, null]). */
    private function whenRange(string $when): array
    {
        switch ($when) {
            case 'today':
                return [now()->startOfDay(), now()->endOfDay()];
            case 'weekend':
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
            'city' => $event->city,
            'when' => optional($event->starts_at)?->setTimezone($event->timezone)->format('D, j M Y'),
            'venue' => $event->is_online ? 'Online' : $event->venue_name,
            'from_price' => $paid->isNotEmpty() ? $paid->min() : null,
            'has_free' => $active->whereIn('kind', ['free', 'donation'])->isNotEmpty(),
        ];
    }
}

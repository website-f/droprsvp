<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventCategory;
use App\Models\Order;
use App\Support\Cities;
use App\Support\EventFaces;
use App\Support\HtmlSanitizer;
use App\Support\SeoManager;
use App\Support\SiteContent;
use App\Support\Url;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
    public function index(Request $request, ?string $city = null, ?string $category = null)
    {
        // A two-segment URL (/en-my/{seg}) may be a city, a category, or a CMS
        // page slug (/en-my/terms/) — they share this space, so resolve in that
        // order and hand anything left over to the CMS before giving up.
        if ($category === null && $city !== null && ! Cities::isKnownSlug($city)) {
            if (EventCategory::where('slug', $city)->exists()) {
                [$city, $category] = [Cities::ANY, $city];
            } else {
                return app(PageController::class)->show($request, $city);
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
            ->withCount(['orders as participants_count' => fn ($q) => $q->where('status', 'paid')])
            ->withCount('reviews')
            ->withAvg('reviews as reviews_avg', 'rating')
            ->when($cityName, fn ($query) => $query->where('city', $cityName))
            ->when($categoryModel, fn ($query) => $query->where('category_id', $categoryModel->id))
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('title', 'like', "%{$q}%")
                ->orWhere('subtitle', 'like', "%{$q}%")
                ->orWhere('description', 'like', "%{$q}%")
                ->orWhere('venue_name', 'like', "%{$q}%")))
            ->when($from && $to, fn ($query) => $query->whereBetween('starts_at', [$from, $to]))
            ->notEnded()
            // Boosted (paid) events surface first.
            ->orderByRaw('(boosted_until is not null and boosted_until > ?) desc', [now()])
            ->orderByRaw('starts_at is null, starts_at asc')
            ->paginate(12)
            ->withQueryString();

        // Attendee names for every card on this page in ONE query. Done before
        // through() so the ids are still readable off the model collection —
        // this used to be a query per card inside card() itself.
        $faces = EventFaces::for($events->getCollection()->pluck('id'));
        $events->through(fn (Event $e) => $this->card($e, $faces));

        $site = config('seo.site_name', 'DropRSVP');
        $catName = $categoryModel?->name;
        $title = $this->heading($catName, $cityName, $q);
        $canonical = $this->pathUrl($cityName ? $citySlug : null, $categoryModel?->slug);

        // The bare "all events" page has admin-editable SEO title + description.
        $isBase = ! $categoryModel && ! $cityName && $q === '';
        $discoverSeo = SiteContent::discoverSeo();
        $title = ($isBase && $discoverSeo['title'] !== '') ? $discoverSeo['title'] : $title;
        $description = ($isBase && $discoverSeo['description'] !== '') ? $discoverSeo['description'] : $this->metaDescription($catName, $cityName, $site);

        $manager = app(SeoManager::class)
            ->title($title)
            ->description($description)
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
                        'url' => Url::to('e', $e['slug']),
                        'name' => $e['title'],
                    ])->values()->all(),
                ],
            ])
            ->breadcrumb($this->breadcrumb($cityName, $citySlug, $categoryModel));

        // The listing as text. There is no Node/SSR in production, so without
        // this a crawler that doesn't run JavaScript got the meta tags and an
        // empty body — the page looked like it had no content at all. The React
        // app renders the same events for real visitors.
        $manager->crawlable($this->crawlableListing($title, $description, $events->getCollection()));

        // City/category pages ARE indexable (the whole point); only free-text
        // search results are kept out of the index.
        if ($q !== '') {
            $manager->noindex();
        }

        // Featured organizer banners — upcoming published events that uploaded a wide
        // banner, respecting the current city/category filter. These become the hero
        // carousel slides (alongside the admin's own default banner).
        $featured = Event::published()
            ->whereNotNull('banner_image')
            ->with('category:id,name,slug')
            ->when($cityName, fn ($query) => $query->where('city', $cityName))
            ->when($categoryModel, fn ($query) => $query->where('category_id', $categoryModel->id))
            ->notEnded()
            ->orderByRaw('(boosted_until is not null and boosted_until > ?) desc', [now()])
            ->orderByRaw('starts_at is null, starts_at asc')
            ->limit(6)
            ->get()
            ->map(fn (Event $e) => [
                'slug' => $e->slug,
                'title' => $e->title,
                'subtitle' => $e->subtitle,
                'banner_image' => $e->banner_image,
                'category' => $e->category?->name,
                'city' => $e->city,
                'when' => $e->starts_at?->setTimezone($e->timezone)->format('D, j M Y'),
                'venue' => $e->is_online ? 'Online' : $e->venue_name,
                'url' => Url::path('e', $e->slug),
            ])->all();

        $eventsPage = SiteContent::eventsPage();

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
            // Events-page hero (admin banner + featured organizer banners) and the
            // foot-of-page SEO text block, both admin-editable.
            'hero' => $eventsPage['hero'],
            'featured' => $featured,
            // The generic block belongs to the unfiltered page only. It used to
            // render on every category too, so "Arts" and "Tech" both carried
            // identical copy — which is duplicate content in Google's eyes and
            // tells a reader nothing about the category they chose. A category
            // page shows its own text instead (see categoryContent below).
            'seoText' => $categoryModel ? null : $eventsPage['seo_text'],
            // Breadcrumb trail for the page header (Home / Events / City / Category),
            // as relative paths for Inertia links.
            'breadcrumbs' => $this->uiBreadcrumb($cityName, $citySlug, $categoryModel),
            // SEO copy shown (truncated, "see more") at the bottom of the page.
            // On a category page: that category's own copy, which is the block the
            // reader actually gets. On the unfiltered page: nothing, because the
            // generic seoText above already covers it and listing every
            // category's copy there was a wall of text.
            'categoryContent' => $categoryModel && $categoryModel->content
                ? [['name' => $categoryModel->name, 'content' => self::copyHtml($categoryModel->content)]]
                : [],
        ]);
    }

    /**
     * Category copy as HTML the page can render.
     *
     * The admin editor writes HTML (<h2>, <p>), but the page printed it as
     * text, so readers saw the tags. Older copy may be plain text with line
     * breaks: that is escaped and turned into paragraphs, so it keeps its
     * shape instead of becoming one run-on line. Sanitised either way — it is
     * admin-written, but it is still published HTML.
     */
    private static function copyHtml(string $content): string
    {
        $content = trim($content);

        // Plain text unless it carries real formatting tags — "a <b>" in prose
        // is a stray bracket, not markup.
        if (! preg_match('/<\/?(p|h[1-6]|ul|ol|li|br|strong|em|b|i|u|a|div|span|blockquote|table|hr)\b/i', $content)) {
            $paragraphs = preg_split('/\R{2,}/', $content) ?: [$content];

            return implode('', array_map(fn ($p) => '<p>'.nl2br(e(trim($p))).'</p>', $paragraphs));
        }

        return HtmlSanitizer::clean($content);
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

    /**
     * Build a discovery path URL. The bare locale (/en-my) is the marketing home,
     * so "all events" lives at /en-my/all.
     */
    private function pathUrl(?string $citySlug, ?string $catSlug): string
    {
        if ($catSlug) {
            return Url::to($citySlug ?: Cities::ANY, $catSlug);
        }

        // /en-my/all/ = browse everything (the bare locale is the landing page).
        return Url::to($citySlug ?: Cities::ANY);
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
            ['name' => 'Home', 'url' => Url::to()],
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

    /** Same crumbs as breadcrumb() but with relative paths, for the on-page UI. */
    private function uiBreadcrumb(?string $cityName, string $citySlug, ?EventCategory $category): array
    {
        $rel = fn (?string $c, ?string $cat) => str_replace(url('/'), '', $this->pathUrl($c, $cat)) ?: '/';
        $crumbs = [
            ['name' => 'Home', 'url' => Url::path()],
            ['name' => 'Events', 'url' => $rel(null, null)],
        ];
        if ($cityName) {
            $crumbs[] = ['name' => $cityName, 'url' => $rel($citySlug, null)];
        }
        if ($category) {
            $crumbs[] = ['name' => $category->name, 'url' => $rel($cityName ? $citySlug : null, $category->slug)];
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
                $start = now()->isWeekend() ? now() : now()->next(Carbon::SATURDAY)->startOfDay();
                $end = $start->copy()->next(Carbon::SUNDAY)->endOfDay();
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

    /**
     * The event listing rendered as plain HTML for crawlers.
     *
     * Mirrors what the React cards show — title, when, venue, city, category —
     * so it is the same content, not a keyword-stuffed alternative version.
     */
    private function crawlableListing(string $heading, ?string $intro, Collection $events): string
    {
        $html = '<h1>'.e($heading).'</h1>';

        if ($intro) {
            $html .= '<p>'.e($intro).'</p>';
        }

        if ($events->isEmpty()) {
            return $html.'<p>No events listed here yet.</p>';
        }

        $html .= '<ul>';

        foreach ($events as $event) {
            $meta = implode(' · ', array_filter([
                $event['when'] ?? null,
                $event['venue'] ?? null,
                $event['city'] ?? null,
                $event['category'] ?? null,
            ]));

            $html .= '<li><a href="'.e(Url::path('e', $event['slug'])).'">'.e($event['title']).'</a>'
                .($meta === '' ? '' : ' — '.e($meta))
                .'</li>';
        }

        return $html.'</ul>';
    }

    /** @param  array<int|string, list<string>>  $faces  prefetched by EventFaces */
    private function card(Event $event, array $faces = []): array
    {
        $active = $event->ticketTypes->where('is_active', true);
        $paid = $active->where('kind', 'paid')->pluck('price')->map(fn ($p) => (float) $p);

        return [
            'slug' => $event->slug,
            'title' => $event->title,
            'cover_image' => $event->cover_image,
            'category' => $event->category?->name,
            'city' => $event->city,
            'boosted' => $event->isBoosted(),
            'when' => $event->starts_at?->setTimezone($event->timezone)->format('D, j M Y'),
            'venue' => $event->is_online ? 'Online' : $event->venue_name,
            'from_price' => $paid->isNotEmpty() ? $paid->min() : null,
            'has_free' => $active->whereIn('kind', ['free', 'donation'])->isNotEmpty(),
            'participants' => (int) ($event->participants_count ?? 0),
            'faces' => $faces[$event->id] ?? [],
            'rating' => ($event->reviews_count ?? 0) > 0 ? round((float) $event->reviews_avg, 1) : null,
            'rating_count' => (int) ($event->reviews_count ?? 0),
        ];
    }
}

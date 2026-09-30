<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventCategory;
use App\Models\Order;
use App\Models\User;
use App\Support\Cities;
use App\Support\EventFaces;
use App\Support\PostCards;
use App\Support\SeoManager;
use App\Support\SiteContent;
use App\Support\Url;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;

class HomeController extends Controller
{
    /** The marketing landing page — featured upcoming events + category tiles. */
    public function index(Request $request)
    {
        $featuredEvents = $this->upcoming()->limit(3)->get();
        $featuredFaces = EventFaces::for($featuredEvents->pluck('id'));
        $featured = $featuredEvents->map(fn (Event $e) => $this->card($e, $featuredFaces))->values();

        // Homepage SEO is superadmin-editable (title/description/keywords/share image).
        $home = SiteContent::homeSeo();
        app(SeoManager::class)
            ->title($home['title'], false)
            ->description($home['description'])
            ->keywords($home['keywords'] ?: null)
            // Blank falls through to the branded site-wide default in SeoManager.
            // No width/height: the admin uploads any size and a wrong og:image:width
            // is worse than none.
            ->image($home['image'] ?: null)
            ->canonical(Url::to())
            ->type('website')
            ->schema([
                '@type' => 'ItemList',
                'name' => 'Featured events',
                'itemListElement' => $featured->map(fn ($e, $i) => [
                    '@type' => 'ListItem',
                    'position' => $i + 1,
                    'url' => Url::to('e', $e['slug']),
                    'name' => $e['title'],
                ])->all(),
            ])
            // The landing page as text. There is no Node/SSR in production, so
            // without this a crawler that doesn't run JavaScript got the meta
            // tags and an empty body — which is why fetching the home page
            // returned metadata and nothing else. Real visitors get the React
            // version and never see this.
            ->crawlable($this->crawlableHome($home, $featured));

        // Nearby cities: only ones that actually have something to show.
        //
        // This was a fixed list an admin typed in, so the chips advertised
        // Ipoh, George Town and Johor Bahru whether or not a single event was
        // running there — and every one of those led to an empty page. The
        // curated list now acts as an ORDER, not as the contents: a city keeps
        // its place if it has upcoming events, and is dropped if it does not.
        $sections = SiteContent::landing();

        if (! empty($sections['nearby_cities']['enabled'])) {
            $sections['nearby_cities']['cities'] = $this->citiesWithEvents(
                (array) ($sections['nearby_cities']['cities'] ?? []),
            );
        }

        return Inertia::render('welcome', [
            // The saved SEO title so the client tab title matches the server <title>
            // (otherwise a hardcoded client title overrides the admin's SEO title).
            'seo' => ['title' => $home['title']],
            'featured' => $featured,
            'categories' => EventCategory::orderBy('sort_order')->orderBy('name')->get(['name', 'slug', 'icon', 'blurb', 'color']),
            'sections' => $sections,
            'organizers' => $this->featuredOrganizers(),
            // Three latest posts for the blog strip above the contact section.
            'posts' => PostCards::recent(3),
            // Personalized feeds for signed-in visitors.
            'cityEvents' => $this->cityEvents($request),
            'forYou' => $this->forYou(),
        ]);
    }

    /**
     * The landing page rendered as plain HTML for crawlers: the admin's own SEO
     * copy, the featured events, and links into the main browse pages so there
     * is a crawlable path deeper into the site.
     */
    private function crawlableHome(array $home, Collection $featured): string
    {
        $html = '<h1>'.e($home['title'] ?: config('seo.site_name', 'DropRSVP')).'</h1>';

        if ($home['description']) {
            $html .= '<p>'.e($home['description']).'</p>';
        }

        if ($featured->isNotEmpty()) {
            $html .= '<h2>Featured events</h2><ul>';

            foreach ($featured as $event) {
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

            $html .= '</ul>';
        }

        $html .= '<h2>Browse</h2><ul>'
            .'<li><a href="'.e(Url::path(Cities::ANY)).'">All events</a></li>'
            .'<li><a href="'.e(Url::path('blog')).'">Blog</a></li>'
            .'<li><a href="'.e(Url::path('help')).'">Help center</a></li>'
            .'<li><a href="'.e(Url::path('contact')).'">Contact</a></li>'
            .'</ul>';

        return $html;
    }

    /** Base query: upcoming published events with the counts the card needs. */
    private function upcoming()
    {
        return Event::published()
            ->with(['category:id,name,slug', 'ticketTypes:id,event_id,kind,price,is_active'])
            ->withCount(['orders as participants_count' => fn ($q) => $q->where('status', 'paid')])
            ->withCount('reviews')
            ->withAvg('reviews as reviews_avg', 'rating')
            ->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '>=', now()->startOfDay()))
            ->orderByRaw('starts_at is null, starts_at asc');
    }

    /**
     * "Events in {city}" for a signed-in visitor — from the browser's location
     * (?near=<city-slug>) if allowed, else their profile city. Null if we don't
     * know their city or there's nothing on there.
     */
    private function cityEvents(Request $request): ?array
    {
        $user = auth()->user();
        if (! $user) {
            return null;
        }

        $near = $request->query('near');
        $cityName = $near ? Cities::nameForSlug($near) : $user->city;
        if (! $cityName) {
            return null;
        }

        $events = $this->upcoming()->where('city', $cityName)->limit(4)->get();
        $faces = EventFaces::for($events->pluck('id'));
        $events = $events->map(fn (Event $e) => $this->card($e, $faces))->values();
        if ($events->isEmpty()) {
            return null;
        }

        return ['city' => $cityName, 'slug' => Cities::slugForName($cityName), 'events' => $events];
    }

    /** "For you" — upcoming events in the categories the user has attended before. */
    private function forYou(): ?Collection
    {
        $user = auth()->user();
        if (! $user) {
            return null;
        }

        $attendedIds = Order::where('user_id', $user->id)->where('status', 'paid')->pluck('event_id')->unique();
        if ($attendedIds->isEmpty()) {
            return null;
        }

        $categoryIds = Event::whereIn('id', $attendedIds)->whereNotNull('category_id')->pluck('category_id')->unique();
        if ($categoryIds->isEmpty()) {
            return null;
        }

        $events = $this->upcoming()->whereIn('category_id', $categoryIds)->whereNotIn('id', $attendedIds)
            ->limit(4)->get();

        if ($events->isEmpty()) {
            return null;
        }

        $faces = EventFaces::for($events->pluck('id'));

        return $events->map(fn (Event $e) => $this->card($e, $faces))->values();
    }

    /** Top organizers by number of published events, with their soonest event. */
    private function featuredOrganizers(): Collection
    {
        $viewer = auth()->user();
        $followingIds = $viewer ? $viewer->following()->pluck('users.id') : collect();

        return User::query()
            ->whereHas('events', fn ($q) => $q->published())
            // Eager-loaded, not fetched per row: this runs for six organizers on
            // every landing-page render.
            ->with('organizerProfile:id,user_id,business_name,poster')
            ->withCount(['events as events_count' => fn ($q) => $q->published()])
            ->withCount('followers')
            ->orderByDesc('events_count')
            ->limit(6)
            ->get()
            ->map(function (User $u) use ($viewer, $followingIds) {
                $next = $u->events()->published()
                    ->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '>=', now()->startOfDay()))
                    ->orderByRaw('starts_at is null, starts_at asc')
                    ->first(['slug']);

                $profile = $u->organizerProfile;

                return [
                    'id' => $u->id,
                    'slug' => $u->ensureSlug(),
                    // Business name and logo, matching the organizer's own public
                    // page. This carried neither, so every card fell back to
                    // initials of a personal name even when a logo was uploaded.
                    'name' => $profile?->business_name ?: $u->name,
                    'avatar' => $profile?->poster ?: $u->avatar,
                    'events_count' => $u->events_count,
                    'followers' => (int) $u->followers_count,
                    'next_slug' => $next?->slug,
                    'is_following' => $followingIds->contains($u->id),
                    'is_self' => $viewer?->id === $u->id,
                ];
            })
            ->values();
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

    /**
     * The nearby-city chips: cities with upcoming published events, in the
     * admin's curated order, with anything they missed appended.
     *
     * Every chip here is a promise that there is something to see. A city with
     * no events leads to an empty browse page, which is a worse first
     * impression than one fewer chip — so the admin's list decides the ORDER
     * and cities without events simply do not appear.
     *
     * @param  array<int,string>  $curated  City names from Admin -> Landing.
     * @return array<int,array{name:string,slug:string,events:int,lat:?float,lng:?float}>
     */
    private function citiesWithEvents(array $curated): array
    {
        $counts = Event::published()
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '>=', now()))
            ->selectRaw('city, count(*) as total')
            ->groupBy('city')
            ->pluck('total', 'city');

        if ($counts->isEmpty()) {
            return [];
        }

        // Curated first, in their order; then anything else that has events,
        // busiest first, so a new city appears on its own without an admin
        // having to remember to add it.
        $ordered = collect($curated)
            ->filter(fn ($name) => $counts->has($name))
            ->merge($counts->sortDesc()->keys()->reject(fn ($name) => in_array($name, $curated, true)))
            ->unique()
            ->take(8);

        return $ordered
            ->map(fn ($name) => [
                'name' => $name,
                'slug' => Cities::slugForName($name),
                'events' => (int) $counts[$name],
                ...(Cities::coordsForName($name) ?? ['lat' => null, 'lng' => null]),
            ])
            ->values()
            ->all();
    }
}

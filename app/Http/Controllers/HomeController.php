<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventCategory;
use App\Models\User;
use App\Support\Cities;
use App\Support\SeoManager;
use App\Support\SiteContent;
use Inertia\Inertia;

class HomeController extends Controller
{
    /** The marketing landing page — featured upcoming events + category tiles. */
    public function index(\Illuminate\Http\Request $request)
    {
        $featured = $this->upcoming()->limit(3)->get()->map(fn (Event $e) => $this->card($e))->values();

        // Homepage SEO is superadmin-editable (title/description/keywords only).
        $home = SiteContent::homeSeo();
        app(SeoManager::class)
            ->title($home['title'], false)
            ->description($home['description'])
            ->keywords($home['keywords'] ?: null)
            ->canonical(url('/en-my'))
            ->type('website')
            ->schema([
                '@type' => 'ItemList',
                'name' => 'Featured events',
                'itemListElement' => $featured->map(fn ($e, $i) => [
                    '@type' => 'ListItem',
                    'position' => $i + 1,
                    'url' => url('/en-my/e/'.$e['slug']),
                    'name' => $e['title'],
                ])->all(),
            ]);

        // Enrich the nearby-cities section with coordinates so the client can show
        // "~N km away" from the visitor's location.
        $sections = SiteContent::landing();
        if (! empty($sections['nearby_cities']['cities'])) {
            $sections['nearby_cities']['cities'] = collect($sections['nearby_cities']['cities'])
                ->map(fn ($name) => ['name' => $name, 'slug' => Cities::slugForName($name), ...(Cities::coordsForName($name) ?? ['lat' => null, 'lng' => null])])
                ->all();
        }

        return Inertia::render('welcome', [
            'featured' => $featured,
            'categories' => EventCategory::orderBy('sort_order')->orderBy('name')->get(['name', 'slug', 'icon', 'blurb', 'color']),
            'sections' => $sections,
            'organizers' => $this->featuredOrganizers(),
            // Personalized feeds for signed-in visitors.
            'cityEvents' => $this->cityEvents($request),
            'forYou' => $this->forYou(),
        ]);
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
    private function cityEvents(\Illuminate\Http\Request $request): ?array
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

        $events = $this->upcoming()->where('city', $cityName)->limit(4)->get()->map(fn (Event $e) => $this->card($e))->values();
        if ($events->isEmpty()) {
            return null;
        }

        return ['city' => $cityName, 'slug' => Cities::slugForName($cityName), 'events' => $events];
    }

    /** "For you" — upcoming events in the categories the user has attended before. */
    private function forYou(): ?\Illuminate\Support\Collection
    {
        $user = auth()->user();
        if (! $user) {
            return null;
        }

        $attendedIds = \App\Models\Order::where('user_id', $user->id)->where('status', 'paid')->pluck('event_id')->unique();
        if ($attendedIds->isEmpty()) {
            return null;
        }

        $categoryIds = Event::whereIn('id', $attendedIds)->whereNotNull('category_id')->pluck('category_id')->unique();
        if ($categoryIds->isEmpty()) {
            return null;
        }

        $events = $this->upcoming()->whereIn('category_id', $categoryIds)->whereNotIn('id', $attendedIds)
            ->limit(4)->get()->map(fn (Event $e) => $this->card($e))->values();

        return $events->isEmpty() ? null : $events;
    }

    /** Top organizers by number of published events, with their soonest event. */
    private function featuredOrganizers(): \Illuminate\Support\Collection
    {
        $viewer = auth()->user();
        $followingIds = $viewer ? $viewer->following()->pluck('users.id') : collect();

        return User::query()
            ->whereHas('events', fn ($q) => $q->published())
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

                return [
                    'id' => $u->id,
                    'slug' => $u->ensureSlug(),
                    'name' => $u->name,
                    'events_count' => $u->events_count,
                    'followers' => (int) $u->followers_count,
                    'next_slug' => $next?->slug,
                    'is_following' => $followingIds->contains($u->id),
                    'is_self' => $viewer?->id === $u->id,
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
            'when' => $event->starts_at?->setTimezone($event->timezone)->format('D, j M Y'),
            'venue' => $event->is_online ? 'Online' : $event->venue_name,
            'from_price' => $paid->isNotEmpty() ? $paid->min() : null,
            'has_free' => $active->whereIn('kind', ['free', 'donation'])->isNotEmpty(),
            'participants' => (int) ($event->participants_count ?? 0),
            'faces' => \App\Models\Order::where('event_id', $event->id)->where('status', 'paid')->whereNotNull('buyer_name')
                ->orderByDesc('paid_at')->limit(8)->pluck('buyer_name')->unique()->take(3)->values()->all(),
            'rating' => ($event->reviews_count ?? 0) > 0 ? round((float) $event->reviews_avg, 1) : null,
            'rating_count' => (int) ($event->reviews_count ?? 0),
        ];
    }
}

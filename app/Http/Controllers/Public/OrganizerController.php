<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventPhoto;
use App\Models\Order;
use App\Models\OrganizerPost;
use App\Models\User;
use App\Support\Cities;
use App\Support\SeoManager;
use App\Support\SeoTemplate;
use App\Support\Url;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class OrganizerController extends Controller
{
    /** Public organizer profile — Meetup-style hub: about, events, members, photos, discussions. */
    public function show(Request $request, User $organizer)
    {
        abort_unless(
            $organizer->hasRole('organizer') || $organizer->events()->published()->exists(),
            404,
        );

        $organizer->loadMissing('organizerProfile');
        $profile = $organizer->organizerProfile;

        $events = $organizer->events()->published()
            ->with(['ticketTypes:id,event_id,kind,price,is_active', 'category:id,name,slug'])
            ->withCount(['orders as participants_count' => fn ($q) => $q->where('status', 'paid')])
            ->withCount('reviews')
            ->withAvg('reviews as reviews_avg', 'rating')
            ->orderByRaw('starts_at is null, starts_at asc')
            ->get()
            ->map(fn (Event $e) => $this->card($e));

        [$upcoming, $past] = $events->partition(fn ($e) => ! $e['is_past']);

        $eventIds = $organizer->events()->pluck('id');
        $user = $request->user();
        $authed = (bool) $user;

        /**
         * Photos are PUBLIC. They are an organizer's shop window — the reason
         * someone decides this is worth going to — and hiding them behind a
         * login turned the most persuasive tab on the page into a dead end.
         *
         * Members are previewed instead of hidden: a guest sees the first few
         * and is told how many more there are, which is an invitation rather
         * than a wall. Names are all a member row carries, so nothing private
         * leaks either way.
         *
         * The discussion stays gated — it is a conversation, not a showcase.
         */
        $preview = 4;

        // Members split: people who've attended (paid) vs people who follow.
        $paid = Order::whereIn('event_id', $eventIds)->where('status', 'paid')->whereNotNull('buyer_email');
        $membersCount = (int) (clone $paid)->distinct('buyer_email')->count('buyer_email');
        $followersCount = (int) $organizer->followers()->count();
        $photos = $this->photos($eventIds);
        $photosCount = $photos->count();

        $attendees = (clone $paid)->orderByDesc('paid_at')->get(['buyer_name', 'buyer_email'])
            ->unique('buyer_email')->take($authed ? 60 : $preview)
            ->map(fn ($o) => ['name' => $o->buyer_name ?: 'Guest'])->values();
        $followers = $organizer->followers()->orderByPivot('created_at', 'desc')
            ->limit($authed ? 60 : $preview)->get(['users.id', 'name'])
            ->map(fn ($u) => ['name' => $u->name])->values();

        // Canonical must be the locale, trailing-slash URL this page is actually
        // served at. It used to be url("/o/{slug}") — the LEGACY path, which 301s
        // to Url::to('o', …), so the canonical pointed at a URL that redirects
        // somewhere else and the page named no valid canonical of its own.
        $canonical = Url::to('o', $organizer->slug);

        // One house template for every organizer page, so each carries the same
        // shape of title and description instead of a name and a bio that may
        // be empty, three words long, or a wall of text.
        $displayName = $profile?->business_name ?: $organizer->name;
        $logo = $profile?->poster ?: $organizer->avatar;

        app(SeoManager::class)
            ->title(SeoTemplate::forOrganizer('organizer_title', $organizer) ?: $displayName)
            ->description(SeoTemplate::forOrganizer('organizer_description', $organizer))
            ->keywords(SeoTemplate::organizerKeywords($organizer))
            ->canonical($canonical)
            ->type('profile')
            ->image($logo)
            ->schemas($this->organizerSchema($organizer, $profile, $canonical, $displayName, $logo, $upcoming))
            // Home -> this profile. There is no organizers index page to sit in
            // between, and pointing that crumb at the events browse page would
            // claim a level of the site that does not exist.
            ->breadcrumb([
                ['name' => 'Home', 'url' => Url::to()],
                ['name' => $displayName, 'url' => $canonical],
            ])
            // The profile as text, for crawlers that don't run JavaScript.
            ->crawlable($this->crawlableProfile($organizer, $profile, $upcoming, $past));

        return inertia('public/organizer', [
            'organizer' => [
                'id' => $organizer->id,
                'slug' => $organizer->slug,
                'name' => $profile?->business_name ?: $organizer->name,
                // Company logo first: this is a business profile, and an
                // organizer who signed up with Google would otherwise show their
                // personal Google photo forever while their uploaded logo sat
                // unused. Falls back to the personal photo when no logo is set.
                'avatar' => $profile?->poster ?: $organizer->avatar,
                'bio' => $profile?->bio,
                'website' => $profile?->website,
                'location' => $organizer->city,
                'event_types' => $profile?->event_types ?? [],
                'followers' => $followersCount,
                'members' => $membersCount,
                'photos_count' => $photosCount,
                'events_count' => $events->count(),
                'joined' => optional($organizer->created_at)->format('M Y'),
            ],
            'upcoming' => $upcoming->values(),
            'past' => $past->values(),
            'members' => [
                'attendees' => $attendees,
                'followers' => $followers,
                // How many a guest is not being shown, so the prompt can say a
                // number instead of a vague "and more".
                'hidden' => $authed ? 0 : max(0, ($membersCount + $followersCount) - $attendees->count() - $followers->count()),
            ],
            'photos' => $photos->take(60)->values(),
            'similar' => $this->similarEvents($organizer, $eventIds),
            'discussion' => $this->discussion($organizer, $request, $authed),
            'viewer' => [
                'authed' => (bool) $user,
                'is_self' => $user?->id === $organizer->id,
                // Admins and the wall's own organizer can moderate: reply on behalf of
                // the organizer (so the reply carries the Organizer badge).
                'can_moderate' => (bool) ($user && ($user->id === $organizer->id || $user->hasRole('superadmin'))),
                'is_following' => $user && $user->id !== $organizer->id ? $user->isFollowing($organizer) : false,
            ],
        ]);
    }

    /**
     * One page of the threaded discussion wall (top-level posts + full nested reply
     * tree). The total is always returned (for the tab badge), but the posts
     * themselves are only exposed to signed-in viewers (auth wall).
     */
    /**
     * Every photo this organizer has published, from both places they end up.
     *
     * An organizer uploads pictures in two different contexts and thinks of
     * them as one body of work:
     *
     *   * the event GALLERY, set while building the event — the promo shots;
     *   * the event ALBUM (event_photos), added afterwards — the photos from
     *     the night itself.
     *
     * The profile used to show only the second, which meant an organizer with
     * a full gallery on every event had an empty Photos tab and no idea why.
     * There was a button to copy gallery images across one by one; that is a
     * chore to keep up with, and forgetting it looks identical to having no
     * photos. Both sources are simply merged here instead, so the tab fills
     * itself as events are published.
     *
     * De-duplicated by path, because an image copied across by the old button
     * exists in both places and must not appear twice.
     *
     * @param  Collection<int, int>  $eventIds
     * @return Collection<int, array{path: string, caption: string|null}>
     */
    private function photos($eventIds): Collection
    {
        if ($eventIds->isEmpty()) {
            return collect();
        }

        // The album: newest first, and each already carries its own caption.
        $album = EventPhoto::whereIn('event_id', $eventIds)
            ->latest()
            ->limit(120)
            ->get(['path', 'caption'])
            ->map(fn (EventPhoto $p) => ['path' => $p->path, 'caption' => $p->caption]);

        // The galleries, newest event first so a recent event's shots lead.
        $gallery = Event::whereIn('id', $eventIds)
            ->whereNotNull('gallery')
            ->orderByRaw('starts_at is null, starts_at desc')
            ->limit(60)
            ->get(['id', 'title', 'gallery'])
            ->flatMap(fn (Event $event) => collect($event->gallery ?? [])
                // The event's name is the only caption a promo image has, and
                // on a profile page it is the useful one.
                ->map(fn ($path) => ['path' => (string) $path, 'caption' => $event->title]));

        return $album->concat($gallery)
            ->filter(fn (array $photo) => $photo['path'] !== '')
            ->unique('path')
            ->values();
    }

    private function discussion(User $organizer, Request $request, bool $authed): array
    {
        $perPage = 10;
        $page = max(1, (int) $request->query('discuss_page', 1));

        $base = OrganizerPost::where('organizer_id', $organizer->id)->whereNull('parent_id');
        $total = (clone $base)->count();

        $posts = $authed
            ? $base->with(['author:id,name', 'repliesRecursive'])
                ->latest()->forPage($page, $perPage)->get()
                ->map(fn ($post) => $this->mapPost($post, $organizer))->all()
            : [];

        return [
            'posts' => $posts,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'has_more' => $authed && $page * $perPage < $total,
            ],
        ];
    }

    /** Recursively shape a post and its nested replies for the client. */
    private function mapPost(OrganizerPost $post, User $organizer): array
    {
        return [
            'id' => $post->id,
            'author' => $post->author?->name ?? 'User',
            'body' => $post->body,
            'when' => $post->created_at?->diffForHumans(),
            'is_organizer' => $post->user_id === $organizer->id,
            'replies' => $post->repliesRecursive->map(fn ($r) => $this->mapPost($r, $organizer))->all(),
        ];
    }

    /** JSON feed for "load more" pagination of the discussion wall (signed-in only). */
    public function discussionFeed(Request $request, User $organizer)
    {
        $this->assertOrganizer($organizer);

        return response()->json($this->discussion($organizer, $request, (bool) $request->user()));
    }

    /** The wall only exists for real organizers (matches show()); 404 otherwise. */
    private function assertOrganizer(User $organizer): void
    {
        abort_unless($organizer->hasRole('organizer') || $organizer->events()->published()->exists(), 404);
    }

    /** Post to the organizer's discussion wall (signed-in users). */
    public function discuss(Request $request, User $organizer)
    {
        $this->assertOrganizer($organizer);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
            'parent_id' => ['nullable', 'integer'],
            'as_organizer' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();
        $canModerate = $user->id === $organizer->id || $user->hasRole('superadmin');

        // A reply must target an existing post on THIS wall (any depth — chains are allowed).
        if (! empty($data['parent_id'])) {
            $parent = OrganizerPost::where('id', $data['parent_id'])
                ->where('organizer_id', $organizer->id)->first();
            abort_unless($parent, 422);
        }

        // Moderators can post as the organizer; everyone else always posts as themselves.
        $asOrganizer = $canModerate && ! empty($data['as_organizer']);

        OrganizerPost::create([
            'organizer_id' => $organizer->id,
            'user_id' => $asOrganizer ? $organizer->id : $user->id,
            'parent_id' => $data['parent_id'] ?? null,
            'body' => $data['body'],
        ]);

        return back()->with('success', 'Posted.');
    }

    /** Upcoming events from OTHER organizers in the same categories — "you might also like". */
    private function similarEvents(User $organizer, $ownEventIds)
    {
        $categoryIds = $organizer->events()->whereNotNull('category_id')->pluck('category_id')->unique();
        if ($categoryIds->isEmpty()) {
            return collect();
        }

        return Event::published()
            ->with(['ticketTypes:id,event_id,kind,price,is_active'])
            ->withCount(['orders as participants_count' => fn ($q) => $q->where('status', 'paid')])
            ->withCount('reviews')->withAvg('reviews as reviews_avg', 'rating')
            ->whereIn('category_id', $categoryIds)
            ->whereNotIn('id', $ownEventIds)
            ->where('user_id', '!=', $organizer->id)
            ->notEnded()
            ->orderByRaw('starts_at is null, starts_at asc')
            ->limit(4)->get()->map(fn (Event $e) => $this->card($e))->values();
    }

    /**
     * The organizer profile rendered as plain HTML for crawlers: the bio plus
     * their upcoming and past events. Only the publicly visible parts — members,
     * photos and the discussion wall sit behind an auth wall and stay out.
     */
    private function crawlableProfile(User $organizer, mixed $profile, Collection $upcoming, Collection $past): string
    {
        $html = '<h1>'.e($profile?->business_name ?: $organizer->name).'</h1>';

        if ($profile?->bio) {
            $html .= '<p>'.e($profile->bio).'</p>';
        }

        foreach ([['Upcoming events', $upcoming], ['Past events', $past]] as [$heading, $events]) {
            if ($events->isEmpty()) {
                continue;
            }

            $html .= '<h2>'.e($heading).'</h2><ul>';

            foreach ($events as $event) {
                $meta = implode(' · ', array_filter([$event['when'] ?? null, $event['venue'] ?? null]));

                $html .= '<li><a href="'.e(Url::path('e', $event['slug'])).'">'.e($event['title']).'</a>'
                    .($meta === '' ? '' : ' — '.e($meta))
                    .'</li>';
            }

            $html .= '</ul>';
        }

        return $html;
    }

    private function absolute(?string $path): ?string
    {
        return $path ? (Str::startsWith($path, ['http://', 'https://']) ? $path : asset($path)) : null;
    }

    private function card(Event $event): array
    {
        $active = $event->ticketTypes->where('is_active', true);
        $paid = $active->where('kind', 'paid')->pluck('price')->map(fn ($p) => (float) $p);

        return [
            'slug' => $event->slug,
            'title' => $event->title,
            'cover_image' => $event->cover_image,
            'when' => $event->starts_at?->setTimezone($event->timezone)->format('D, j M Y'),
            'venue' => $event->is_online ? 'Online' : $event->venue_name,
            'from_price' => $paid->isNotEmpty() ? $paid->min() : null,
            'has_free' => $active->whereIn('kind', ['free', 'donation'])->isNotEmpty(),
            'participants' => (int) ($event->participants_count ?? 0),
            'rating' => ($event->reviews_count ?? 0) > 0 ? round((float) $event->reviews_avg, 1) : null,
            'is_past' => $event->starts_at !== null && $event->starts_at->isPast(),
        ];
    }

    /**
     * The organizer profile as structured data.
     *
     * Three nodes that reference each other, which is how Google reads a
     * profile page: the page itself, the Organization it is about, and the
     * events they are running. The event list is what makes this page eligible
     * to show sitelinks to individual events rather than just a name.
     *
     * @param  Collection<int,array>  $upcoming
     */
    private function organizerSchema(User $organizer, $profile, string $canonical, string $displayName, ?string $logo, $upcoming): array
    {
        $url = Url::slash($canonical);
        $orgId = $url.'#organizer';

        $organization = array_filter([
            '@type' => 'Organization',
            '@id' => $orgId,
            'name' => $displayName,
            'url' => $url,
            'description' => $profile?->bio ? Str::limit(trim(strip_tags($profile->bio)), 300) : null,
            'logo' => $logo ? ['@type' => 'ImageObject', 'url' => $this->absolute($logo)] : null,
            'image' => $logo ? $this->absolute($logo) : null,
            'address' => $organizer->city ? array_filter([
                '@type' => 'PostalAddress',
                'addressLocality' => $organizer->city,
                'addressRegion' => Cities::stateForCity($organizer->city),
                'addressCountry' => 'MY',
            ]) : null,
            // Only their own site — never the phone or email, which are on the
            // profile for attendees to use, not for scrapers to harvest.
            'sameAs' => $profile?->website ? [$profile->website] : null,
        ], fn ($v) => $v !== null && $v !== []);

        $page = array_filter([
            '@type' => 'ProfilePage',
            '@id' => $url.'#profilepage',
            'url' => $url,
            'name' => $displayName,
            'mainEntity' => ['@id' => $orgId],
        ]);

        $nodes = [$page, $organization];

        // Their upcoming events, in order, each pointing at its own page.
        $items = collect($upcoming)->take(20)->values()
            ->map(fn ($e, $i) => array_filter([
                '@type' => 'ListItem',
                'position' => $i + 1,
                'name' => $e['title'] ?? null,
                'url' => isset($e['slug']) ? Url::slash(Url::to('e', $e['slug'])) : null,
            ]))
            ->filter(fn ($item) => ! empty($item['url']))
            ->values()
            ->all();

        if ($items) {
            $nodes[] = [
                '@type' => 'ItemList',
                '@id' => $url.'#events',
                'name' => "Upcoming events by {$displayName}",
                'numberOfItems' => count($items),
                'itemListElement' => $items,
            ];
        }

        return $nodes;
    }
}

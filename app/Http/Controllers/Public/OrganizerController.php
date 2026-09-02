<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Order;
use App\Models\User;
use App\Support\SeoManager;
use Illuminate\Http\Request;

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

        // Members split: people who've attended (paid) vs people who follow.
        $paid = Order::whereIn('event_id', $eventIds)->where('status', 'paid')->whereNotNull('buyer_email');
        $membersCount = (int) (clone $paid)->distinct('buyer_email')->count('buyer_email');
        $attendees = (clone $paid)->orderByDesc('paid_at')->get(['buyer_name', 'buyer_email'])
            ->unique('buyer_email')->take(60)->map(fn ($o) => ['name' => $o->buyer_name ?: 'Guest'])->values();
        $followers = $organizer->followers()->orderByPivot('created_at', 'desc')->limit(60)->get(['users.id', 'name'])
            ->map(fn ($u) => ['name' => $u->name])->values();

        app(SeoManager::class)
            ->title("{$organizer->name} — events on ".config('seo.site_name', 'DropRSVP'))
            ->description($profile?->bio ?: "See every event hosted by {$organizer->name} and follow for updates.")
            ->canonical(url("/o/{$organizer->slug}"))
            ->type('profile');

        return inertia('public/organizer', [
            'organizer' => [
                'id' => $organizer->id,
                'slug' => $organizer->slug,
                'name' => $profile?->business_name ?: $organizer->name,
                'avatar' => $organizer->avatar ?: $profile?->poster,
                'bio' => $profile?->bio,
                'website' => $profile?->website,
                'location' => $organizer->city,
                'event_types' => $profile?->event_types ?? [],
                'followers' => (int) $organizer->followers()->count(),
                'members' => $membersCount,
                'events_count' => $events->count(),
                'joined' => optional($organizer->created_at)->format('M Y'),
            ],
            'upcoming' => $upcoming->values(),
            'past' => $past->values(),
            'members' => ['attendees' => $attendees, 'followers' => $followers],
            'photos' => \App\Models\EventPhoto::whereIn('event_id', $eventIds)->latest()->limit(60)
                ->get(['path', 'caption'])->map(fn ($p) => ['path' => $p->path, 'caption' => $p->caption])->values(),
            'similar' => $this->similarEvents($organizer, $eventIds),
            'discussion' => $this->discussion($organizer, $request),
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

    /** One page of the threaded discussion wall (top-level posts + full nested reply tree). */
    private function discussion(User $organizer, Request $request): array
    {
        $perPage = 10;
        $page = max(1, (int) $request->query('discuss_page', 1));

        $base = \App\Models\OrganizerPost::where('organizer_id', $organizer->id)->whereNull('parent_id');
        $total = (clone $base)->count();

        $posts = $base->with(['author:id,name', 'repliesRecursive'])
            ->latest()->forPage($page, $perPage)->get()
            ->map(fn ($post) => $this->mapPost($post, $organizer))->all();

        return [
            'posts' => $posts,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'has_more' => $page * $perPage < $total,
            ],
        ];
    }

    /** Recursively shape a post and its nested replies for the client. */
    private function mapPost(\App\Models\OrganizerPost $post, User $organizer): array
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

    /** JSON feed for "load more" pagination of the discussion wall. */
    public function discussionFeed(Request $request, User $organizer)
    {
        return response()->json($this->discussion($organizer, $request));
    }

    /** Post to the organizer's discussion wall (signed-in users). */
    public function discuss(Request $request, User $organizer)
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
            'parent_id' => ['nullable', 'integer'],
            'as_organizer' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();
        $canModerate = $user->id === $organizer->id || $user->hasRole('superadmin');

        // A reply must target an existing post on THIS wall (any depth — chains are allowed).
        if (! empty($data['parent_id'])) {
            $parent = \App\Models\OrganizerPost::where('id', $data['parent_id'])
                ->where('organizer_id', $organizer->id)->first();
            abort_unless($parent, 422);
        }

        // Moderators can post as the organizer; everyone else always posts as themselves.
        $asOrganizer = $canModerate && ! empty($data['as_organizer']);

        \App\Models\OrganizerPost::create([
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
            ->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '>=', now()->startOfDay()))
            ->orderByRaw('starts_at is null, starts_at asc')
            ->limit(4)->get()->map(fn (Event $e) => $this->card($e))->values();
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
}

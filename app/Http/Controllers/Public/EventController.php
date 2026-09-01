<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventDailyStat;
use App\Models\EventReview;
use App\Models\Order;
use App\Support\SeoManager;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EventController extends Controller
{
    /** Public event page (server-rendered for SEO). */
    public function show(Request $request, Event $event)
    {
        abort_unless($this->visibleTo($request, $event), 404);

        $event->load([
            'category',
            'user',
            'seo',
            'sessions',
            // Only manual (general-admission) ticket types in the normal selector —
            // seat-section-backed ones are bought through the seat map instead.
            'ticketTypes' => fn ($q) => $q->where('is_active', true)->whereNull('seat_section_id')->orderBy('sort_order'),
            'seatSections' => fn ($q) => $q->orderBy('sort_order')->with(['seats' => fn ($s) => $s->orderBy('sort_order'), 'ticketType']),
        ]);

        $description = $this->metaDescription($event);
        $cover = $event->cover_image ? $this->absolute($event->cover_image) : null;
        $canonical = url("/en-my/e/{$event->slug}");
        $organizer = $event->user?->name ?? 'DropRSVP';
        $seo = $event->seo;
        $isPublic = $event->status === 'published' && in_array($event->visibility, ['public', 'unlisted'], true);

        // Ratings (shown on the page + as aggregateRating in JSON-LD).
        $ratingCount = (int) $event->reviews()->count();
        $ratingAvg = $ratingCount ? round((float) $event->reviews()->avg('rating'), 1) : 0.0;

        // Count a public impression (not the organizer previewing their own event).
        if ($isPublic && $request->user()?->id !== $event->user_id) {
            EventDailyStat::bump($event->id, 'impressions');
        }

        // --- server-rendered SEO (no JS needed) ---
        $manager = app(SeoManager::class)
            ->title($seo?->seo_title ?: $event->title)
            ->description($seo?->meta_description ?: $description)
            ->keywords($seo?->meta_keywords)
            ->canonical($seo?->canonical_url ?: $canonical)
            ->image($seo?->og_image ? $this->absolute($seo->og_image) : $cover)
            ->schema($this->eventSchema($event, $description, $cover, $canonical, $organizer, $ratingAvg, $ratingCount))
            ->breadcrumb([
                ['name' => 'Home', 'url' => url('/en-my')],
                ['name' => 'Events', 'url' => url('/en-my/all')],
                ['name' => $event->title, 'url' => $canonical],
            ]);
        // Draft / owner-preview pages must never be indexed.
        $isPublic ? $manager->robots((bool) ($seo->robots_index ?? true), (bool) ($seo->robots_follow ?? true)) : $manager->noindex();

        // --- social: members + discussion (with free/premium gating) ---
        // Superadmins + the organizer always have full access; everyone else needs Premium.
        $user = $request->user();
        $isOwner = $user?->id === $event->user_id;
        $isPremium = (bool) $user?->isPremium();
        $hasAccess = (bool) $user?->hasPremiumAccess();
        $canSeeAllMembers = $hasAccess || $isOwner;
        $canPost = $user && ($hasAccess || $isOwner);

        // Reviews: any signed-in user may rate, except the organizer of the event.
        $canReview = (bool) $user && ! $isOwner;
        $myReview = $user ? $event->reviews()->where('user_id', $user->id)->first() : null;
        $participantsPage = max(1, (int) $request->query('participants_page', 1));
        $reviewsPage = max(1, (int) $request->query('reviews_page', 1));
        $discussionPage = max(1, (int) $request->query('discussion_page', 1));

        return inertia('public/event', [
            'event' => [
                'slug' => $event->slug,
                'title' => $event->title,
                'subtitle' => $event->subtitle,
                'description' => $event->description,
                'cover_image' => $cover,
                'gallery' => collect($event->gallery ?? [])->map(fn ($g) => $this->absolute($g))->values()->all(),
                'category' => $event->category?->name,
                'is_online' => $event->is_online,
                'venue_name' => $event->venue_name,
                'venue_address' => $event->venue_address,
                'online_url' => $event->online_url,
                'starts_at' => optional($event->starts_at)->toIso8601String(),
                'ends_at' => optional($event->ends_at)->toIso8601String(),
                'when' => $this->fmt($event, $event->starts_at),   // pre-formatted (no client TZ drift)
                'organizer' => $organizer,
                'organizer_id' => $event->user_id,
                'organizer_slug' => $event->user?->ensureSlug(),
                'organizer_followers' => (int) ($event->user?->followers()->count() ?? 0),
                'status' => $event->status,
                'show_participants' => (bool) $event->show_participants,
                'show_reviews' => (bool) $event->show_reviews,
                'seating_enabled' => (bool) $event->seating_enabled,
                'seating' => $event->seating_enabled ? $event->seatSections->map(fn ($sec) => [
                    'id' => $sec->id,
                    'ticket_type_id' => $sec->ticket_type_id,
                    'name' => $sec->name,
                    'color' => $sec->color,
                    'kind' => $sec->kind,
                    'price' => (float) $sec->price,
                    'currency' => $sec->currency,
                    'rows' => $sec->rows,
                    'cols' => $sec->cols,
                    'curve' => (int) $sec->curve,
                    'x' => (int) $sec->x,
                    'y' => (int) $sec->y,
                    'width' => $sec->width,
                    'height' => $sec->height,
                    'remaining' => $sec->ticketType?->remaining(),
                    'on_sale' => $sec->kind === 'stage' ? false : (bool) $sec->ticketType?->isOnSale(),
                    'seats' => $sec->kind === 'seated' ? $sec->seats->map(fn ($seat) => [
                        'id' => $seat->id,
                        'label' => $seat->label,
                        'row' => $seat->row_label,
                        'number' => $seat->number,
                        'taken' => $seat->status !== 'available',
                    ])->values() : [],
                ])->values() : [],
                'sessions' => $event->sessions->map(fn ($s) => [
                    'id' => $s->id,
                    'title' => $s->title,
                    'label' => $this->fmt($event, $s->starts_at),
                    'starts_at' => optional($s->starts_at)->toIso8601String(),
                    'ends_at' => optional($s->ends_at)->toIso8601String(),
                ]),
                'ticket_types' => $event->ticketTypes->map(fn ($t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'description' => $t->description,
                    'kind' => $t->kind,
                    'price' => (float) $t->price,
                    'compare_at_price' => $t->compare_at_price ? (float) $t->compare_at_price : null,
                    'currency' => $t->currency,
                    'on_sale' => $t->isOnSale(),
                    'sold_out' => $t->remaining() === 0,
                    'min_per_order' => $t->min_per_order,
                    'max_per_order' => $t->max_per_order,
                    'remaining' => $t->remaining(),
                ]),
            ],
            'seo' => [
                'title' => $seo?->seo_title ?: $event->title,
            ],
            'participants' => $this->participants($event, $canSeeAllMembers, $participantsPage),
            'discussion' => $this->discussion($event, $discussionPage),
            'reviews' => $this->reviews($event, $myReview, $reviewsPage),
            'viewer' => [
                'authed' => (bool) $user,
                'premium' => $isPremium,
                'is_owner' => $isOwner,
                'can_post' => $canPost,
                'can_see_all_members' => $canSeeAllMembers,
                'can_review' => $canReview,
                'has_reviewed' => (bool) $myReview,
                'is_following' => $user && ! $isOwner ? $user->isFollowing($event->user) : false,
            ],
        ]);
    }

    /**
     * People who got tickets. Free/guest see the first 4 (the rest paywalled);
     * premium + the organizer see the full list, paginated.
     */
    private function participants(Event $event, bool $canSeeAll, int $page): array
    {
        $perPage = 12;
        $paid = Order::where('event_id', $event->id)->where('status', 'paid')->whereNotNull('buyer_email');
        $total = (int) (clone $paid)->distinct('buyer_email')->count('buyer_email');
        $rows = (clone $paid)->orderByDesc('paid_at')->get(['buyer_name', 'buyer_email'])->unique('buyer_email')->values();

        if (! $canSeeAll) {
            return [
                'count' => $total,
                'unlocked' => false,
                'list' => $rows->take(4)->map(fn ($m) => ['name' => $m->buyer_name ?: 'Guest'])->values()->all(),
                'page' => 1,
                'pages' => 1,
            ];
        }

        $pages = max(1, (int) ceil($rows->count() / $perPage));
        $page = min($page, $pages);

        return [
            'count' => $total,
            'unlocked' => true,
            'list' => $rows->slice(($page - 1) * $perPage, $perPage)
                ->map(fn ($m) => ['name' => $m->buyer_name ?: 'Guest'])->values()->all(),
            'page' => $page,
            'pages' => $pages,
        ];
    }

    /**
     * Ratings + reviews. The average + star distribution are computed over ALL
     * reviews (cheap DB aggregates), but the list itself is paginated so events
     * with thousands of reviews stay fast.
     */
    private function reviews(Event $event, ?EventReview $mine, int $page): array
    {
        $perPage = 8;
        $count = (int) $event->reviews()->count();
        $average = $count ? round((float) $event->reviews()->avg('rating'), 1) : 0.0;
        $dist = $event->reviews()->selectRaw('rating, count(*) as c')->groupBy('rating')->pluck('c', 'rating');
        $pages = max(1, (int) ceil($count / $perPage));
        $page = min(max(1, $page), $pages);

        return [
            'average' => $average,
            'count' => $count,
            'distribution' => collect(range(5, 1))->mapWithKeys(fn ($s) => [$s => (int) ($dist[$s] ?? 0)])->all(),
            'list' => $event->reviews()->with('user:id,name')->latest()->forPage($page, $perPage)->get()->map(fn ($r) => [
                'id' => $r->id,
                'author' => $r->user?->name ?? 'Attendee',
                'rating' => $r->rating,
                'body' => $r->body,
                'when' => $r->created_at->diffForHumans(),
                'mine' => $mine && $r->id === $mine->id,
            ])->values()->all(),
            'page' => $page,
            'pages' => $pages,
            'mine' => $mine ? ['rating' => $mine->rating, 'body' => $mine->body] : null,
        ];
    }

    /** Threaded discussion — top-level questions with the organizer's replies (paginated). */
    private function discussion(Event $event, int $page): array
    {
        $perPage = 8;
        $count = (int) $event->comments()->count();
        $pages = max(1, (int) ceil($count / $perPage));
        $page = min(max(1, $page), $pages);

        return [
            'count' => $count,
            'page' => $page,
            'pages' => $pages,
            'list' => $event->comments()->with(['user:id,name', 'replies.user:id,name'])->forPage($page, $perPage)->get()->map(fn ($c) => [
                'id' => $c->id,
                'author' => $c->user?->name ?? 'User',
                'body' => $c->body,
                'when' => $c->created_at->diffForHumans(),
                'is_organizer' => $c->user_id === $event->user_id,
                'replies' => $c->replies->map(fn ($r) => [
                    'id' => $r->id,
                    'author' => $r->user?->name ?? 'User',
                    'body' => $r->body,
                    'when' => $r->created_at->diffForHumans(),
                    'is_organizer' => $r->user_id === $event->user_id,
                ])->all(),
            ])->all(),
        ];
    }

    /** Published public/unlisted events are visible to all; the owner can preview any of their own. */
    private function visibleTo(Request $request, Event $event): bool
    {
        if ($event->status === 'published' && in_array($event->visibility, ['public', 'unlisted'], true)) {
            return true;
        }

        return $request->user()?->id === $event->user_id;
    }

    /** Human date label in the event's own timezone, formatted server-side. */
    private function fmt(Event $event, $dt): ?string
    {
        return $dt ? $dt->copy()->setTimezone($event->timezone)->format('D, j M Y · g:i A') : null;
    }

    private function metaDescription(Event $event): string
    {
        $text = trim(strip_tags((string) $event->description)) ?: (string) $event->subtitle;

        return Str::limit($text, 155);
    }

    private function absolute(string $path): string
    {
        return Str::startsWith($path, ['http://', 'https://']) ? $path : asset($path);
    }

    /** schema.org/Event JSON-LD for rich results. Null fields are pruned. */
    private function eventSchema(Event $event, string $description, ?string $cover, string $url, string $organizer, float $ratingAvg = 0.0, int $ratingCount = 0): array
    {
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'Event',
            'name' => $event->title,
            'description' => $description,
            'url' => $url,
            'startDate' => optional($event->starts_at)->toIso8601String(),
            'endDate' => optional($event->ends_at)->toIso8601String(),
            'eventStatus' => $event->status === 'cancelled'
                ? 'https://schema.org/EventCancelled'
                : 'https://schema.org/EventScheduled',
            'eventAttendanceMode' => $event->is_online
                ? 'https://schema.org/OnlineEventAttendanceMode'
                : 'https://schema.org/OfflineEventAttendanceMode',
            'location' => $event->is_online
                ? ['@type' => 'VirtualLocation', 'url' => $event->online_url]
                : array_filter([
                    '@type' => 'Place',
                    'name' => $event->venue_name,
                    'address' => $event->venue_address,
                ]),
            'image' => $cover ? [$cover] : null,
            'organizer' => ['@type' => 'Organization', 'name' => $organizer],
            'offers' => $event->ticketTypes->map(fn ($t) => array_filter([
                '@type' => 'Offer',
                'name' => $t->name,
                'price' => number_format((float) $t->price, 2, '.', ''),
                'priceCurrency' => $t->currency,
                'availability' => $t->remaining() === 0
                    ? 'https://schema.org/SoldOut'
                    : 'https://schema.org/InStock',
                'url' => $url,
            ]))->values()->all(),
        ];

        if ($ratingCount > 0) {
            $schema['aggregateRating'] = [
                '@type' => 'AggregateRating',
                'ratingValue' => $ratingAvg,
                'reviewCount' => $ratingCount,
            ];
        }

        return array_filter($schema, fn ($v) => $v !== null && $v !== []);
    }
}

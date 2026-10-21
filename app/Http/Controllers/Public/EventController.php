<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventDailyStat;
use App\Models\EventReview;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Support\Chat\ChatLink;
use App\Support\Cities;
use App\Support\HtmlSanitizer;
use App\Support\Ics;
use App\Support\SeoManager;
use App\Support\SeoTemplate;
use App\Support\Url;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class EventController extends Controller
{
    /** Public event page (server-rendered for SEO). */
    public function show(Request $request, Event $event)
    {
        // A cancelled event is taken down — send the public to the homepage rather
        // than a bare 404 (the owner can still preview it).
        if ($event->status === 'cancelled' && $request->user()?->id !== $event->user_id) {
            return redirect()->route('home')->with('warning', 'That event has been cancelled.');
        }

        abort_unless($this->visibleTo($request, $event), 404);

        $event->load([
            'category',
            // The profile comes with the user: the SEO templates credit the
            // event to the BUSINESS name, so it is needed on every render.
            'user.organizerProfile',
            'seo',
            'sessions',
            // Only manual (general-admission) ticket types in the normal selector —
            // seat-section-backed ones are bought through the seat map instead.
            'ticketTypes' => fn ($q) => $q->where('is_active', true)->whereNull('seat_section_id')->orderBy('sort_order'),
            'seatSections' => fn ($q) => $q->orderBy('sort_order')->with(['seats' => fn ($s) => $s->orderBy('sort_order'), 'ticketType']),
            // Promo codes the organizer chose to advertise. A code nobody can
            // discover is only useful if the organizer posts it somewhere else;
            // this is the platform's own way to run a visible offer.
            'discountCodes' => fn ($q) => $q->advertisable()->orderBy('id'),
        ]);

        $description = $this->metaDescription($event);
        $cover = $event->cover_image ? $this->absolute($event->cover_image) : null;
        $banner = $event->banner_image ? $this->absolute($event->banner_image) : null;
        $canonical = url("/en-my/e/{$event->slug}");
        // The trading name, as the title and description use — the JSON-LD
        // organizer was still the account holder's personal name.
        $organizer = SeoTemplate::organizerName($event->user);
        $seo = $event->seo;
        $isPublic = $event->status === 'published' && in_array($event->visibility, ['public', 'unlisted'], true);

        // Whether it is over, and what its tickets cost. Both feed the page, the
        // crawler text and the JSON-LD, so they are worked out once here.
        $ended = $event->hasEnded();
        $priced = $this->pricedTicketTypes($event, $ended);

        // Ratings (shown on the page + as aggregateRating in JSON-LD).
        $ratingCount = (int) $event->reviews()->count();
        $ratingAvg = $ratingCount ? round((float) $event->reviews()->avg('rating'), 1) : 0.0;

        // Count a public impression (not the organizer previewing their own event).
        if ($isPublic && $request->user()?->id !== $event->user_id) {
            EventDailyStat::bump($event->id, 'impressions');
        }

        // Resolve any {token} placeholders in the admin-authored SEO copy.
        $seoTitle = SeoTemplate::render($seo?->seo_title, $event);
        $seoDesc = SeoTemplate::render($seo?->meta_description, $event);
        $seoKeywords = SeoTemplate::render($seo?->meta_keywords, $event);

        // No per-event override? Fall back to the house template rather than the
        // bare title and a truncated description. Across hundreds of events that
        // is the difference between every result reading "Some Event Name" and
        // every result naming the city and the date.
        $houseTitle = SeoTemplate::forEvent('event_title', $event);
        $houseDesc = SeoTemplate::forEvent('event_description', $event);

        $pageTitle = $seoTitle ?: ($houseTitle ?: $event->title);

        // --- server-rendered SEO (no JS needed) ---
        $manager = app(SeoManager::class)
            ->title($pageTitle)
            ->description($seoDesc ?: ($houseDesc ?: $description))
            // Date and place, spelled out where the title can only abbreviate.
            ->keywords($seoKeywords ?: SeoTemplate::eventKeywords($event))
            ->canonical($seo?->canonical_url ?: $canonical)
            ->image($seo?->og_image ? $this->absolute($seo->og_image) : $cover, null, null, $houseTitle ?: $event->title)
            ->schema($this->eventSchema($event, $description, $cover, $canonical, $organizer, $ratingAvg, $ratingCount, $priced, $ended))
            ->breadcrumb([
                ['name' => 'Home', 'url' => Url::to()],
                ['name' => 'Events', 'url' => Url::to('all')],
                ['name' => $event->title, 'url' => $canonical],
            ]);
        foreach (SeoTemplate::geoMeta($event) as $name => $content) {
            $manager->meta($name, $content);
        }
        // When it starts and ends, as machine-readable meta beside the JSON-LD.
        $tz = $event->timezone ?: config('app.timezone');
        if ($event->starts_at) {
            $manager->meta('event:start_time', $event->starts_at->setTimezone($tz)->toIso8601String(), true);
        }
        if ($event->ends_at) {
            $manager->meta('event:end_time', $event->ends_at->setTimezone($tz)->toIso8601String(), true);
        }

        // Draft / owner-preview pages must never be indexed.
        $isPublic ? $manager->robots((bool) ($seo->robots_index ?? true), (bool) ($seo->robots_follow ?? true)) : $manager->noindex();

        // The event as text. There is no Node/SSR in production, so without this
        // a crawler that doesn't run JavaScript got the meta tags and an empty
        // body. Real visitors get the React version and never see it.
        $related = $this->relatedEvents($event);
        $manager->crawlable($this->crawlableEvent($event, $description, $organizer, $priced, $ended, $related));

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
                'banner_image' => $banner,
                // The organizer's photos from the day lead, then the promo images:
                // once an event is over, what it looked like is the better page.
                'gallery' => $this->galleryFor($event),
                'category' => $event->category?->name,
                'is_online' => $event->is_online,
                'venue_name' => $event->venue_name,
                'venue_address' => $event->venue_address,
                'online_url' => $event->online_url,
                'starts_at' => optional($event->starts_at)->toIso8601String(),
                'ends_at' => optional($event->ends_at)->toIso8601String(),
                // Past events keep their page (links in the wild, the blog, receipts)
                // but say so, stop selling, and point at what is coming up instead.
                'has_ended' => $ended,
                // What a ticket costs, stated in the header rather than only in the
                // ticket box further down — "Free", "RM 25.00", "RM 25.00 – RM 80.00".
                'price_label' => $this->priceLabel($priced),
                'price_list' => $priced->map(fn ($t) => ['name' => $t->name, 'price' => $this->money($t)])->values()->all(),
                'when' => $this->fmt($event, $event->starts_at),   // pre-formatted (no client TZ drift)
                'sold_out' => $event->ticketTypes->isNotEmpty() && $event->ticketTypes->every(fn ($t) => $t->remaining() === 0),
                'google_url' => $event->status === 'published' ? Ics::googleUrl($event) : null,
                'ics_url' => $event->status === 'published' ? route('events.ics', $event) : null,
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
                    'on_sale' => ! $ended && $t->isOnSale(),
                    'sold_out' => $t->remaining() === 0,
                    'min_per_order' => $t->min_per_order,
                    'max_per_order' => $t->max_per_order,
                    'remaining' => $t->remaining(),
                ]),
            ],
            // Only ever public codes — scopeAdvertisable does the filtering, so a
            // private partner code can never reach the page payload.
            'offers' => $event->discountCodes->map(fn ($code) => [
                'code' => $code->code,
                'label' => $code->offerLabel(),
                'min_subtotal' => $code->min_subtotal !== null ? (float) $code->min_subtotal : null,
                'ends_at' => $code->ends_at?->setTimezone($event->timezone)->format('j M Y'),
                // What is left when the organizer capped it — the scarcity is
                // real and worth stating, but only when it is getting close.
                'remaining' => $code->max_redemptions !== null
                    ? max(0, $code->max_redemptions - $code->redemptions)
                    : null,
            ])->values(),
            'seo' => [
                'title' => $pageTitle,
            ],
            'related' => $related,
            'participants' => $this->participants($event, $canSeeAllMembers, $participantsPage, $user, $isOwner),
            'discussion' => $this->discussion($event, $discussionPage, $user),
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
     * People who got tickets.
     *
     * "Going" counts tickets, not buyers, so it tallies with the tickets sold:
     * one person buying four seats brings four people. Only the holder has a
     * name on the platform, so the list shows a card per named holder and
     * gathers the rest into "N others" — four names and three others for seven
     * tickets, rather than four names claiming to be everyone.
     *
     * Free/guest see the first 4 names (the rest paywalled); premium + the
     * organizer see the full list, paginated.
     */
    private function participants(Event $event, bool $canSeeAll, int $page, ?User $viewer = null, bool $isOwner = false): array
    {
        $perPage = 12;

        $tickets = $event->tickets()->live()
            ->with('order:id,buyer_name,buyer_email,user_id,paid_at')
            ->get(['id', 'order_id', 'attendee_name', 'attendee_email']);

        $going = $tickets->count();

        // One entry per person we can name, newest ticket first. A ticket that
        // was passed on carries the new holder's details, so it stands on its
        // own rather than under the buyer.
        $holders = $tickets
            ->map(function (Ticket $ticket) {
                $email = $ticket->attendee_email ?: $ticket->order?->buyer_email;

                return [
                    'email' => $email ? mb_strtolower($email) : null,
                    'name' => $ticket->attendee_name ?: ($ticket->order?->buyer_name ?: 'Guest'),
                    // Only the buyer's own ticket points back at their account.
                    'user_id' => $email && strcasecmp($email, (string) $ticket->order?->buyer_email) === 0
                        ? $ticket->order?->user_id
                        : null,
                    'at' => $ticket->order?->paid_at,
                ];
            })
            ->filter(fn (array $h) => $h['email'] !== null)
            ->sortByDesc('at')
            ->unique('email')
            ->values();

        $named = $holders->count();

        // Message buttons only between people at the same event: the organizer,
        // or someone holding a ticket themselves.
        $canMessage = $viewer && ($isOwner || $holders->contains(fn (array $h) => (int) $h['user_id'] === $viewer->id
            || strcasecmp((string) $h['email'], (string) $viewer->email) === 0));
        $person = fn (array $h) => ['name' => $h['name'], 'chat' => $canMessage ? ChatLink::for($viewer, $h['user_id']) : null];

        if (! $canSeeAll) {
            $list = $holders->take(4);

            return [
                'count' => $going,
                'unlocked' => false,
                'list' => $list->map($person)->values()->all(),
                // The remainder only makes sense once every name is on screen;
                // otherwise it would double up with what the paywall hides.
                'others' => $named <= $list->count() ? max(0, $going - $named) : 0,
                'page' => 1,
                'pages' => 1,
            ];
        }

        $pages = max(1, (int) ceil($named / $perPage));
        $page = min($page, $pages);

        return [
            'count' => $going,
            'unlocked' => true,
            'list' => $holders->slice(($page - 1) * $perPage, $perPage)
                ->map($person)->values()->all(),
            'others' => $page === $pages ? max(0, $going - $named) : 0,
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
        // reorder() clears the relation's default "latest" ORDER BY, which otherwise
        // breaks GROUP BY under MySQL's only_full_group_by mode.
        $dist = $event->reviews()->reorder()->selectRaw('rating, count(*) as c')->groupBy('rating')->pluck('c', 'rating');
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
    private function discussion(Event $event, int $page, ?User $viewer = null): array
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
                'chat' => ChatLink::for($viewer, $c->user_id),
                'body' => $c->body,
                'when' => $c->created_at->diffForHumans(),
                'is_organizer' => $c->user_id === $event->user_id,
                'replies' => $c->replies->map(fn ($r) => [
                    'id' => $r->id,
                    'author' => $r->user?->name ?? 'User',
                    'chat' => ChatLink::for($viewer, $r->user_id),
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

    /**
     * The ticket types whose prices the page states.
     *
     * Every active type, including the seat-section ones (bought through the
     * seat map, so absent from the ticket selector, but a price all the same).
     * Once an event is over its organizer often switches the tickets off, which
     * left the page saying nothing about price and the JSON-LD calling the event
     * free; a past event falls back to every type it sold.
     */
    private function pricedTicketTypes(Event $event, bool $ended): Collection
    {
        // Both halves are already loaded with the page, so the usual case
        // costs no query of its own.
        $active = $event->ticketTypes
            ->concat($event->seatSections->pluck('ticketType')->filter(fn ($t) => $t && $t->is_active))
            ->unique('id')
            ->sortBy('sort_order')
            ->values();

        if ($active->isNotEmpty() || ! $ended) {
            return $active;
        }

        return $event->ticketTypes()->orderBy('sort_order')->get();
    }

    /** One ticket's price as the page shows it: "Free", "Donation", "RM 25.00". */
    private function money(TicketType $type): string
    {
        if ($type->kind === 'donation') {
            return 'Donation';
        }

        if ($type->kind === 'free' || (float) $type->price <= 0) {
            return 'Free';
        }

        return $this->currency($type->currency).' '.number_format((float) $type->price, 2);
    }

    private function currency(?string $code): string
    {
        return ($code ?: 'MYR') === 'MYR' ? 'RM' : (string) $code;
    }

    /**
     * The price at a glance: "Free", "RM 25.00", "RM 25.00 – RM 80.00", or
     * "Free – RM 80.00" when there are free and paid tickets. Null when the
     * event has no tickets to price.
     */
    private function priceLabel(Collection $types): ?string
    {
        if ($types->isEmpty()) {
            return null;
        }

        $paid = $types->filter(fn ($t) => $t->kind === 'paid' && (float) $t->price > 0);

        if ($paid->isEmpty()) {
            return $types->every(fn ($t) => $t->kind === 'donation') ? 'Donation' : 'Free';
        }

        $symbol = $this->currency($paid->first()->currency);
        $format = fn (float $amount) => $symbol.' '.number_format($amount, 2);
        $low = (float) $paid->min(fn ($t) => (float) $t->price);
        $high = (float) $paid->max(fn ($t) => (float) $t->price);

        if ($paid->count() < $types->count()) {
            return 'Free – '.$format($high);
        }

        return $low === $high ? $format($low) : $format($low).' – '.$format($high);
    }

    /** The organizer's own photos from the day first, then the promo images. */
    private function galleryFor(Event $event): array
    {
        return $event->photos()->limit(60)->pluck('path')
            ->concat($event->gallery ?? [])
            ->filter(fn ($path) => is_string($path) && $path !== '')
            ->unique()
            ->map(fn (string $path) => $this->absolute($path))
            ->values()
            ->all();
    }

    /**
     * Upcoming events to send someone on to: the same organizer's first, then
     * others in the same category.
     *
     * On a past event's page this is the point of the page — whoever lands on
     * it from an old link or the blog should find what is on next rather than
     * a dead end. On an upcoming event it is simply "more like this".
     */
    private function relatedEvents(Event $event): array
    {
        $upcoming = fn () => Event::published()->notEnded()
            ->where('id', '!=', $event->id)
            ->with('ticketTypes:id,event_id,kind,price,currency,is_active')
            ->orderByRaw('starts_at is null, starts_at asc');

        $mine = $upcoming()->where('user_id', $event->user_id)->limit(4)->get();

        $similar = $mine->count() < 4 && $event->category_id
            ? $upcoming()->where('category_id', $event->category_id)
                ->whereNotIn('id', $mine->pluck('id'))
                ->limit(4 - $mine->count())
                ->get()
            : collect();

        return $mine->concat($similar)
            ->map(fn (Event $e) => [
                'slug' => $e->slug,
                'title' => $e->title,
                'cover_image' => $e->cover_image ? $this->absolute($e->cover_image) : null,
                'when' => $e->starts_at?->setTimezone($e->timezone ?: config('app.timezone'))->format('D, j M Y'),
                'venue' => $e->is_online ? 'Online' : ($e->venue_name ?: $e->city),
                'price' => $this->priceLabel($e->ticketTypes->where('is_active', true)->values()),
                'same_organizer' => $e->user_id === $event->user_id,
            ])
            ->values()
            ->all();
    }

    private function absolute(string $path): string
    {
        return Str::startsWith($path, ['http://', 'https://']) ? $path : asset($path);
    }

    /** schema.org/Event JSON-LD for rich results. Null fields are pruned. */
    /**
     * The event page rendered as plain HTML for crawlers — the same facts the
     * React page shows (what, when, where, who, and the description), so this is
     * the same content rather than an alternative version of it.
     */
    private function crawlableEvent(Event $event, string $description, string $organizer, Collection $priced, bool $ended, array $related): string
    {
        $tz = $event->timezone ?: config('app.timezone');
        $starts = $event->starts_at?->setTimezone($tz);
        $ends = $event->ends_at?->setTimezone($tz);
        $url = Url::to('e', $event->slug);

        $when = $starts?->format('l, j F Y, g:ia')
            .($ends ? ' – '.$ends->format($ends->isSameDay($starts) ? 'g:ia' : 'l, j F Y, g:ia') : '');

        $html = '<nav aria-label="Breadcrumb"><a href="'.e(Url::to()).'">Home</a> › '
            .'<a href="'.e(Url::to('all')).'">Events</a> › '.e($event->title).'</nav>';

        $html .= '<article><h1>'.e($event->title).'</h1>';

        if ($event->subtitle) {
            $html .= '<p>'.e($event->subtitle).'</p>';
        }

        if ($ended) {
            $html .= '<p><strong>This event has ended.</strong>'
                .($starts ? ' It took place on '.e($starts->format('l, j F Y')).'.' : '')
                .($related !== [] ? ' See the upcoming events below.' : '').'</p>';
        }

        // ---- the facts -------------------------------------------------------
        $html .= '<dl>';
        if ($when !== '') {
            $html .= '<dt>When</dt><dd>'.e($when).'</dd>';
        }
        $html .= '<dt>Where</dt><dd>'.e($event->is_online
            ? 'Online event'
            : implode(', ', array_filter([$event->venue_name, $event->venue_address, $event->city]))).'</dd>';
        $html .= '<dt>Organizer</dt><dd>'
            .($event->user?->slug
                ? '<a href="'.e(Url::path('o', $event->user->slug)).'">'.e($organizer).'</a>'
                : e($organizer))
            .'</dd>';
        if ($event->category?->name) {
            $html .= '<dt>Category</dt><dd>'.e($event->category->name).'</dd>';
        }
        $html .= '<dt>Refunds</dt><dd>'.e($event->refundPolicyLabel()).'</dd>';
        $html .= '</dl>';

        // ---- the whole description, not the 155-character meta snippet ----------
        //
        // This used to print the truncated meta description, so a crawler read
        // "If you are new to this game, don't worry. No prior experience is..."
        // and stopped there. The description is authored in the rich editor and
        // sanitised on save; it is cleaned again here because this is raw HTML
        // going into the page. Plain-text descriptions from before the editor
        // keep their line breaks.
        $body = (string) $event->description;

        if (trim(strip_tags($body)) !== '') {
            $html .= '<h2>About this event</h2>';
            $html .= preg_match('/<[a-z][\s\S]*>/i', $body)
                ? '<div>'.HtmlSanitizer::clean($body).'</div>'
                : '<p>'.nl2br(e($body)).'</p>';
        } elseif ($description !== '') {
            $html .= '<p>'.e($description).'</p>';
        }

        // ---- the schedule, when it is more than one sitting ---------------------
        if ($event->sessions->count() > 1) {
            $html .= '<h2>Schedule</h2><ul>';
            foreach ($event->sessions as $session) {
                $at = $session->starts_at?->setTimezone($tz);
                $until = $session->ends_at?->setTimezone($tz);
                $html .= '<li>'.e(implode(' — ', array_filter([
                    $session->title,
                    $at ? $at->format('D, j M Y, g:ia').($until ? ' – '.$until->format('g:ia') : '') : null,
                ]))).'</li>';
            }
            $html .= '</ul>';
        }

        // ---- tickets: what it costs and whether you can still get in ------------
        // Seat-section types are listed below by their section instead.
        $tickets = $priced->whereNull('seat_section_id')->map(function ($t) use ($ended) {
            $price = $t->kind === 'free' || (float) $t->price <= 0
                ? 'Free'
                : ($t->currency ?: 'MYR').' '.number_format((float) $t->price, 2);
            $remaining = $t->remaining();
            $state = match (true) {
                $ended => 'Event ended',
                $remaining === 0 => 'Sold out',
                ! $t->isOnSale() => 'Not on sale',
                $remaining !== null && $remaining <= 20 => $remaining.' left',
                default => 'Available',
            };

            return '<li><strong>'.e($t->name).'</strong> — '.e($price).' ('.e($state).')'
                .($t->description ? '<br>'.e($t->description) : '').'</li>';
        });

        // Reserved-seating events sell by section rather than ticket type.
        $sections = $event->seating_enabled
            ? $event->seatSections->where('kind', '!=', 'stage')->map(fn ($sec) => '<li><strong>'.e($sec->name).'</strong> — '
                .e((float) $sec->price > 0 ? ($sec->currency ?: 'MYR').' '.number_format((float) $sec->price, 2) : 'Free')
                .($sec->ticketType && $sec->ticketType->remaining() === 0 ? ' (Sold out)' : '').'</li>')
            : collect();

        if ($tickets->isNotEmpty() || $sections->isNotEmpty()) {
            $html .= '<h2>Tickets</h2><ul>'.$tickets->implode('').$sections->implode('').'</ul>';
        }

        if ($event->status === 'published' && ! $ended) {
            $html .= '<p><a href="'.e($url.'#tickets').'">Get tickets for '.e($event->title).' on DropRSVP</a></p>';
        }

        // ---- where to go next ----------------------------------------------------
        if ($related !== []) {
            $html .= '<h2>Upcoming events</h2><ul>';
            foreach ($related as $card) {
                $html .= '<li><a href="'.e(Url::path('e', $card['slug'])).'">'.e($card['title']).'</a>'
                    .($card['when'] ? ' — '.e($card['when']) : '').'</li>';
            }
            $html .= '</ul>';
        }

        return $html.'</article>';
    }

    /**
     * schema.org/Event JSON-LD.
     *
     * This is what earns the rich result — the date, the venue with a real
     * postal address, the price range and whether tickets are still available,
     * all in the shape Google validates against. It was previously thin: a
     * free-text address line, no offer validity window, no price range, no
     * capacity, no geo.
     */
    private function eventSchema(Event $event, string $description, ?string $cover, string $url, string $organizer, float $ratingAvg, int $ratingCount, Collection $priced, bool $ended): array
    {
        $timezone = $event->timezone ?: config('app.timezone');
        $starts = $event->starts_at?->setTimezone($timezone);
        $ends = $event->ends_at?->setTimezone($timezone);

        $images = array_values(array_filter(array_merge(
            [$cover, $event->banner_image ? $this->absolute($event->banner_image) : null],
            collect($event->gallery ?? [])->take(4)->map(fn ($g) => $this->absolute($g))->all(),
        )));

        $offers = $this->offerSchema($event, $url, $priced, $ended);
        $prices = $priced->filter(fn ($t) => $t->kind === 'paid')->map(fn ($t) => (float) $t->price)->filter(fn ($p) => $p > 0);

        $schema = [
            '@type' => 'Event',
            '@id' => $url.'#event',
            'name' => $event->title,
            'description' => $description,
            'url' => $url,
            'startDate' => $starts?->toIso8601String(),
            'endDate' => $ends?->toIso8601String(),
            'eventStatus' => $event->status === 'cancelled'
                ? 'https://schema.org/EventCancelled'
                : 'https://schema.org/EventScheduled',
            'eventAttendanceMode' => $event->is_online
                ? 'https://schema.org/OnlineEventAttendanceMode'
                : 'https://schema.org/OfflineEventAttendanceMode',
            'location' => $this->locationSchema($event),
            'image' => $images ?: null,
            'inLanguage' => str_replace('_', '-', (string) config('seo.locale', 'en_MY')),
            'organizer' => array_filter([
                '@type' => 'Organization',
                'name' => $organizer,
                'url' => $event->user?->slug ? Url::slash(Url::to('o', $event->user->slug)) : null,
            ]),
            // Google treats performer as the act on stage. For a hosted event
            // the organizer is the closest true answer, and leaving the field
            // out entirely loses something the rich result can show.
            'performer' => ['@type' => 'Organization', 'name' => $organizer],
            // Only a claim when there are tickets to judge by. With none it
            // used to say true, so a paid event whose tickets had been switched
            // off was announced to search engines as free.
            'isAccessibleForFree' => $priced->isNotEmpty() ? $prices->isEmpty() : null,
            'offers' => $offers ?: null,
        ];

        if ($event->category?->name) {
            // Not a schema.org enum, so it goes in as a plain keyword rather
            // than an invented URL.
            $schema['keywords'] = $event->category->name;
            $schema['about'] = ['@type' => 'Thing', 'name' => $event->category->name];
        }

        if ($event->capacity) {
            $schema['maximumAttendeeCapacity'] = (int) $event->capacity;
        }

        if ($prices->isNotEmpty() && $offers) {
            // An aggregate in front of the individual offers, so a result can
            // say "from RM25" without reading every ticket type.
            $schema['offers'] = array_merge([array_filter([
                '@type' => 'AggregateOffer',
                'priceCurrency' => $priced->first()?->currency ?: 'MYR',
                'lowPrice' => number_format($prices->min(), 2, '.', ''),
                'highPrice' => number_format($prices->max(), 2, '.', ''),
                'offerCount' => count($offers),
                // Nothing is on sale for an event that is over, and saying
                // InStock about it would be wrong; the prices stay, as a record.
                'availability' => $ended ? null : $this->availability($priced),
                'url' => $url,
            ])], $offers);
        }

        if ($ratingCount > 0) {
            $schema['aggregateRating'] = [
                '@type' => 'AggregateRating',
                'ratingValue' => $ratingAvg,
                'bestRating' => 5,
                'worstRating' => 1,
                'reviewCount' => $ratingCount,
            ];
        }

        return array_filter($schema, fn ($v) => $v !== null && $v !== []);
    }

    /**
     * One Offer per ticket type, with its own availability and validity window.
     * Free tickets are offers too — price 0.00 in MYR — so a free event still
     * states its price rather than leaving it to be guessed.
     */
    private function offerSchema(Event $event, string $url, Collection $priced, bool $ended): array
    {
        return $priced
            ->map(function ($t) use ($event, $url, $ended) {
                $remaining = $t->remaining();

                return array_filter([
                    '@type' => 'Offer',
                    'name' => $t->name,
                    'price' => number_format((float) $t->price, 2, '.', ''),
                    'priceCurrency' => $t->currency ?: 'MYR',
                    'availability' => match (true) {
                        $ended => null,
                        $remaining === 0 => 'https://schema.org/SoldOut',
                        default => 'https://schema.org/InStock',
                    },
                    // When this offer can be bought. Google warns about offers
                    // with no validity window, and "from publication until the
                    // doors open" is the honest answer for an event ticket.
                    'validFrom' => optional($event->published_at ?: $event->created_at)->toIso8601String(),
                    'validThrough' => optional($event->starts_at)->toIso8601String(),
                    'url' => $url,
                ], fn ($v) => $v !== null && $v !== '');
            })
            ->values()
            ->all();
    }

    /** Whether any ticket at all is still available. */
    private function availability(Collection $priced): string
    {
        return $priced->contains(fn ($t) => $t->remaining() !== 0)
            ? 'https://schema.org/InStock'
            : 'https://schema.org/SoldOut';
    }

    /**
     * Where it is, as a Place with a structured PostalAddress.
     *
     * A single free-text address line is valid but weak: splitting out the
     * locality, region and country is what lets a result place the venue
     * properly. The street line is whatever remains once the city has been
     * taken off the end of it, so the city is not stated twice in one node.
     */
    private function locationSchema(Event $event): array
    {
        if ($event->is_online) {
            return array_filter(['@type' => 'VirtualLocation', 'url' => $event->online_url]);
        }

        $city = trim((string) ($event->city ?? ''));
        $street = trim((string) ($event->venue_address ?? ''));

        if ($city !== '' && str_ends_with(mb_strtolower($street), mb_strtolower($city))) {
            $street = rtrim(mb_substr($street, 0, -mb_strlen($city)), " \t\n,");
        }

        $place = [
            '@type' => 'Place',
            'name' => $event->venue_name ?: ($city ?: null),
            'address' => array_filter([
                '@type' => 'PostalAddress',
                'streetAddress' => $street ?: null,
                'addressLocality' => $city ?: null,
                'addressRegion' => $city !== '' ? Cities::stateForCity($city) : null,
                'addressCountry' => 'MY',
            ]),
        ];

        if ($event->latitude && $event->longitude) {
            $place['geo'] = [
                '@type' => 'GeoCoordinates',
                'latitude' => (float) $event->latitude,
                'longitude' => (float) $event->longitude,
            ];
        }

        return array_filter($place, fn ($v) => $v !== null && $v !== [] && $v !== '');
    }
}

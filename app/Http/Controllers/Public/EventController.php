<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventDailyStat;
use App\Models\EventReview;
use App\Models\Order;
use App\Support\Cities;
use App\Support\HtmlSanitizer;
use App\Support\Ics;
use App\Support\SeoManager;
use App\Support\SeoTemplate;
use App\Support\Url;
use Illuminate\Http\Request;
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
            ->schema($this->eventSchema($event, $description, $cover, $canonical, $organizer, $ratingAvg, $ratingCount))
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
        $manager->crawlable($this->crawlableEvent($event, $description, $organizer));

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
                'gallery' => collect($event->gallery ?? [])->map(fn ($g) => $this->absolute($g))->values()->all(),
                'category' => $event->category?->name,
                'is_online' => $event->is_online,
                'venue_name' => $event->venue_name,
                'venue_address' => $event->venue_address,
                'online_url' => $event->online_url,
                'starts_at' => optional($event->starts_at)->toIso8601String(),
                'ends_at' => optional($event->ends_at)->toIso8601String(),
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
                    'on_sale' => $t->isOnSale(),
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
    /**
     * The event page rendered as plain HTML for crawlers — the same facts the
     * React page shows (what, when, where, who, and the description), so this is
     * the same content rather than an alternative version of it.
     */
    private function crawlableEvent(Event $event, string $description, string $organizer): string
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
        $tickets = $event->ticketTypes->map(function ($t) {
            $price = $t->kind === 'free' || (float) $t->price <= 0
                ? 'Free'
                : ($t->currency ?: 'MYR').' '.number_format((float) $t->price, 2);
            $remaining = $t->remaining();
            $state = match (true) {
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

        if ($event->status === 'published') {
            $html .= '<p><a href="'.e($url.'#tickets').'">Get tickets for '.e($event->title).' on DropRSVP</a></p>';
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
    private function eventSchema(Event $event, string $description, ?string $cover, string $url, string $organizer, float $ratingAvg = 0.0, int $ratingCount = 0): array
    {
        $timezone = $event->timezone ?: config('app.timezone');
        $starts = $event->starts_at?->setTimezone($timezone);
        $ends = $event->ends_at?->setTimezone($timezone);

        $images = array_values(array_filter(array_merge(
            [$cover, $event->banner_image ? $this->absolute($event->banner_image) : null],
            collect($event->gallery ?? [])->take(4)->map(fn ($g) => $this->absolute($g))->all(),
        )));

        $offers = $this->offerSchema($event, $url);
        $prices = collect($event->ticketTypes)->map(fn ($t) => (float) $t->price)->filter(fn ($p) => $p > 0);

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
            'isAccessibleForFree' => $prices->isEmpty(),
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
            $schema['offers'] = array_merge([[
                '@type' => 'AggregateOffer',
                'priceCurrency' => $event->ticketTypes->first()?->currency ?: 'MYR',
                'lowPrice' => number_format($prices->min(), 2, '.', ''),
                'highPrice' => number_format($prices->max(), 2, '.', ''),
                'offerCount' => count($offers),
                'availability' => $this->availability($event),
                'url' => $url,
            ]], $offers);
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

    /** One Offer per ticket type, with its own availability and validity window. */
    private function offerSchema(Event $event, string $url): array
    {
        return $event->ticketTypes
            ->map(function ($t) use ($event, $url) {
                $remaining = $t->remaining();

                return array_filter([
                    '@type' => 'Offer',
                    'name' => $t->name,
                    'price' => number_format((float) $t->price, 2, '.', ''),
                    'priceCurrency' => $t->currency ?: 'MYR',
                    'availability' => $remaining === 0
                        ? 'https://schema.org/SoldOut'
                        : 'https://schema.org/InStock',
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
    private function availability(Event $event): string
    {
        return $event->ticketTypes->contains(fn ($t) => $t->remaining() !== 0)
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

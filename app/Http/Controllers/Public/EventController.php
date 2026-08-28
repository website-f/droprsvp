<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventDailyStat;
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
            'ticketTypes' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order'),
        ]);

        $description = $this->metaDescription($event);
        $cover = $event->cover_image ? $this->absolute($event->cover_image) : null;
        $canonical = url("/e/{$event->slug}");
        $organizer = $event->user?->name ?? 'DropRSVP';
        $seo = $event->seo;
        $isPublic = $event->status === 'published' && in_array($event->visibility, ['public', 'unlisted'], true);

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
            ->schema($this->eventSchema($event, $description, $cover, $canonical, $organizer))
            ->breadcrumb([
                ['name' => 'Home', 'url' => url('/')],
                ['name' => 'Events', 'url' => url('/events')],
                ['name' => $event->title, 'url' => $canonical],
            ]);
        // Draft / owner-preview pages must never be indexed.
        $isPublic ? $manager->robots((bool) ($seo->robots_index ?? true), (bool) ($seo->robots_follow ?? true)) : $manager->noindex();

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
                'status' => $event->status,
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
        ]);
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
    private function eventSchema(Event $event, string $description, ?string $cover, string $url, string $organizer): array
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

        return array_filter($schema, fn ($v) => $v !== null && $v !== []);
    }
}

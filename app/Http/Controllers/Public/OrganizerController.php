<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\User;
use App\Support\SeoManager;
use Illuminate\Http\Request;

class OrganizerController extends Controller
{
    /** Public organizer profile — their details + every event they host. */
    public function show(Request $request, User $organizer)
    {
        abort_unless(
            $organizer->hasRole('organizer') || $organizer->events()->published()->exists(),
            404,
        );

        $events = $organizer->events()->published()
            ->with(['ticketTypes:id,event_id,kind,price,is_active'])
            ->withCount(['orders as participants_count' => fn ($q) => $q->where('status', 'paid')])
            ->withCount('reviews')
            ->withAvg('reviews as reviews_avg', 'rating')
            ->orderByRaw('starts_at is null, starts_at asc')
            ->get()
            ->map(fn (Event $e) => $this->card($e));

        [$upcoming, $past] = $events->partition(fn ($e) => ! $e['is_past']);

        $user = $request->user();
        $canonical = url("/o/{$organizer->id}");

        app(SeoManager::class)
            ->title("{$organizer->name} — events on ".config('seo.site_name', 'DropRSVP'))
            ->description("See every event hosted by {$organizer->name} and follow for updates.")
            ->canonical($canonical)
            ->type('profile');

        return inertia('public/organizer', [
            'organizer' => [
                'id' => $organizer->id,
                'name' => $organizer->name,
                'followers' => (int) $organizer->followers()->count(),
                'events_count' => $events->count(),
                'joined' => optional($organizer->created_at)->format('M Y'),
            ],
            'upcoming' => $upcoming->values(),
            'past' => $past->values(),
            'viewer' => [
                'authed' => (bool) $user,
                'is_self' => $user?->id === $organizer->id,
                'is_following' => $user && $user->id !== $organizer->id ? $user->isFollowing($organizer) : false,
            ],
        ]);
    }

    private function card(Event $event): array
    {
        $active = $event->ticketTypes->where('is_active', true);
        $paid = $active->where('kind', 'paid')->pluck('price')->map(fn ($p) => (float) $p);

        return [
            'slug' => $event->slug,
            'title' => $event->title,
            'cover_image' => $event->cover_image,
            'when' => optional($event->starts_at)?->setTimezone($event->timezone)->format('D, j M Y'),
            'venue' => $event->is_online ? 'Online' : $event->venue_name,
            'from_price' => $paid->isNotEmpty() ? $paid->min() : null,
            'has_free' => $active->whereIn('kind', ['free', 'donation'])->isNotEmpty(),
            'participants' => (int) ($event->participants_count ?? 0),
            'rating' => ($event->reviews_count ?? 0) > 0 ? round((float) $event->reviews_avg, 1) : null,
            'is_past' => $event->starts_at !== null && $event->starts_at->isPast(),
        ];
    }
}

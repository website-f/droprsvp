<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Dedicated per-event SEO manager: list every event, preview its search snippet
 * and edit the title, description, keywords, canonical, robots and social meta.
 */
class EventSeoController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        $events = Event::query()
            ->when($q !== '', fn ($x) => $x->where('title', 'like', "%{$q}%"))
            ->with('seo')
            ->latest()
            ->paginate(12)
            ->withQueryString()
            ->through(fn (Event $e) => [
                'slug' => $e->slug,
                'title' => $e->title,
                'status' => $e->status,
                'customised' => (bool) ($e->seo && ($e->seo->seo_title || $e->seo->meta_description || $e->seo->meta_keywords)),
                'preview_title' => $e->seo?->seo_title ?: $e->title,
                'preview_desc' => $e->seo?->meta_description ?: $this->defaultDescription($e),
            ]);

        return inertia('admin/seo/events/index', [
            'events' => $events,
            'filters' => ['q' => $q],
            'baseUrl' => url('/en-my/e'),
        ]);
    }

    public function edit(Event $event)
    {
        $seo = $event->seo;

        return inertia('admin/seo/events/edit', [
            'event' => [
                'slug' => $event->slug,
                'title' => $event->title,
                'status' => $event->status,
            ],
            'seo' => [
                'seo_title' => $seo?->seo_title,
                'meta_description' => $seo?->meta_description,
                'focus_keyphrase' => $seo?->focus_keyphrase,
                'meta_keywords' => $seo?->meta_keywords,
                'canonical_url' => $seo?->canonical_url,
                'robots_index' => (bool) ($seo->robots_index ?? true),
                'robots_follow' => (bool) ($seo->robots_follow ?? true),
                'og_title' => $seo?->og_title,
                'og_description' => $seo?->og_description,
                'og_image' => $seo?->og_image,
            ],
            'fallback' => [
                'title' => $event->title,
                'description' => $this->defaultDescription($event),
            ],
            'baseUrl' => url('/en-my/e'),
        ]);
    }

    public function update(Request $request, Event $event)
    {
        $data = $request->validate([
            'slug' => ['required', 'string', 'max:200'],
            'seo.seo_title' => ['nullable', 'string', 'max:180'],
            'seo.meta_description' => ['nullable', 'string', 'max:320'],
            'seo.focus_keyphrase' => ['nullable', 'string', 'max:120'],
            'seo.meta_keywords' => ['nullable', 'string', 'max:500'],
            'seo.canonical_url' => ['nullable', 'string', 'max:2048'],
            'seo.robots_index' => ['boolean'],
            'seo.robots_follow' => ['boolean'],
            'seo.og_title' => ['nullable', 'string', 'max:180'],
            'seo.og_description' => ['nullable', 'string', 'max:320'],
            'seo.og_image' => ['nullable', 'string', 'max:2048'],
        ]);

        // Slug drives the event URL — only touch it if the admin changed it, and
        // keep it unique.
        $desired = Str::slug($data['slug']) ?: $event->slug;
        if ($desired !== $event->slug) {
            $event->slug = $this->uniqueSlug($desired, $event->id);
            $event->save();
        }

        $event->seo()->updateOrCreate([], $data['seo']);

        return back()->with('success', 'Event SEO saved.');
    }

    private function defaultDescription(Event $event): string
    {
        $text = trim(strip_tags((string) $event->description)) ?: (string) $event->subtitle;

        return Str::limit($text, 155);
    }

    private function uniqueSlug(string $base, int $exceptId): string
    {
        $slug = $base;
        $i = 2;
        while (Event::withTrashed()->where('slug', $slug)->where('id', '!=', $exceptId)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}

<?php

namespace App\Http\Controllers\Host;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventPhoto;
use Illuminate\Http\Request;

/**
 * Organizer photo album for an event — pictures taken during/after the event,
 * shown on the organizer's public "Photos" tab. Separate from the promo `gallery`.
 */
class EventPhotoController extends Controller
{
    public function index(Event $event)
    {
        $this->authorize('update', $event);

        $photos = $event->photos()->get(['id', 'path', 'caption']);
        $already = $photos->pluck('path')->all();

        return inertia('host/events/photos', [
            'event' => ['title' => $event->title, 'slug' => $event->slug],
            'photos' => $photos->map(fn ($p) => [
                'id' => $p->id, 'path' => $p->path, 'caption' => $p->caption,
            ]),
            // The event's promo gallery, offered as a one-tap source for this
            // album. Organizers were re-uploading the same files by hand: the
            // images are already on the event, they just had no way to say "and
            // show these on my profile too". Anything already added is filtered
            // out, so the picker only ever offers something that would change.
            'galleryOptions' => array_values(array_filter(
                (array) ($event->gallery ?? []),
                fn ($path) => ! in_array($path, $already, true),
            )),
        ]);
    }

    public function store(Request $request, Event $event)
    {
        $this->authorize('update', $event);

        $data = $request->validate([
            'paths' => ['required', 'array', 'min:1', 'max:30'],
            'paths.*' => ['string', 'max:2048'],
        ]);

        foreach ($data['paths'] as $path) {
            $event->photos()->create(['path' => $path, 'uploaded_by' => $request->user()->id]);
        }

        return back()->with('success', count($data['paths']).' photo(s) added.');
    }

    /**
     * Copy images from the event's promo gallery into this album.
     *
     * Only paths that are actually on THIS event's gallery are accepted — the
     * request names them, and without that check it would be an open "insert
     * any URL into my profile" endpoint. Already-added paths are skipped rather
     * than rejected, so a double submit is harmless.
     */
    public function fromGallery(Request $request, Event $event)
    {
        $this->authorize('update', $event);

        $data = $request->validate([
            'paths' => ['required', 'array', 'min:1', 'max:30'],
            'paths.*' => ['string', 'max:2048'],
        ]);

        $gallery = (array) ($event->gallery ?? []);
        $existing = $event->photos()->pluck('path')->all();

        $wanted = array_values(array_filter(
            array_unique($data['paths']),
            fn (string $path) => in_array($path, $gallery, true) && ! in_array($path, $existing, true),
        ));

        if ($wanted === []) {
            return back()->with('flash_error', 'Those photos are already in the album.');
        }

        foreach ($wanted as $path) {
            $event->photos()->create(['path' => $path, 'uploaded_by' => $request->user()->id]);
        }

        return back()->with('success', count($wanted).' photo(s) added from the event gallery.');
    }

    public function destroy(Request $request, Event $event, EventPhoto $photo)
    {
        $this->authorize('update', $event);
        abort_unless($photo->event_id === $event->id, 404);

        $photo->delete();

        return back()->with('success', 'Photo removed.');
    }
}

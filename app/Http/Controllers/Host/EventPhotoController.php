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

        return inertia('host/events/photos', [
            'event' => ['title' => $event->title, 'slug' => $event->slug],
            'photos' => $event->photos()->get(['id', 'path', 'caption'])->map(fn ($p) => [
                'id' => $p->id, 'path' => $p->path, 'caption' => $p->caption,
            ]),
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

    public function destroy(Request $request, Event $event, EventPhoto $photo)
    {
        $this->authorize('update', $event);
        abort_unless($photo->event_id === $event->id, 404);

        $photo->delete();

        return back()->with('success', 'Photo removed.');
    }
}

<?php

namespace App\Http\Controllers\Host;

use App\Http\Controllers\Controller;
use App\Mail\WaitlistInvite;
use App\Models\Event;
use App\Models\WaitlistEntry;
use App\Support\Mailer;
use Illuminate\Http\Request;

class WaitlistController extends Controller
{
    /** The organizer's view of who's waiting for a spot. */
    public function index(Request $request, Event $event)
    {
        $this->authorize('update', $event);

        $entries = $event->waitlistEntries()->latest()->get();

        return inertia('host/events/waitlist', [
            'event' => ['title' => $event->title, 'slug' => $event->slug],
            'entries' => $entries->map(fn (WaitlistEntry $e) => [
                'id' => $e->id,
                'name' => $e->name,
                'email' => $e->email,
                'status' => $e->status,
                'joined' => optional($e->created_at)->format('j M Y'),
                'notified_at' => optional($e->notified_at)->format('j M Y'),
            ]),
            'waiting' => $entries->where('status', 'waiting')->count(),
        ]);
    }

    /** Invite one waitlisted person to come and buy. */
    public function notify(Request $request, Event $event, WaitlistEntry $entry)
    {
        $this->authorize('update', $event);
        abort_unless($entry->event_id === $event->id, 404);

        $this->invite($event, $entry);

        return back()->with('flash_success', "Invited {$entry->name}.");
    }

    /** Invite everyone still waiting. */
    public function notifyAll(Request $request, Event $event)
    {
        $this->authorize('update', $event);

        $waiting = $event->waitlistEntries()->where('status', 'waiting')->get();
        foreach ($waiting as $entry) {
            $this->invite($event, $entry);
        }

        return back()->with('flash_success', 'Invited '.$waiting->count().' '.($waiting->count() === 1 ? 'person' : 'people').'.');
    }

    private function invite(Event $event, WaitlistEntry $entry): void
    {
        Mailer::defer($entry->email, new WaitlistInvite($event, $entry->name));
        $entry->update(['status' => 'notified', 'notified_at' => now()]);
    }
}

<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\WaitlistEntry;
use Illuminate\Http\Request;

class WaitlistController extends Controller
{
    /** A visitor joins the waitlist for a sold-out event. */
    public function join(Request $request, Event $event)
    {
        abort_unless($event->status === 'published', 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
        ]);

        // One entry per email per event — re-joining just refreshes the name.
        WaitlistEntry::updateOrCreate(
            ['event_id' => $event->id, 'email' => mb_strtolower($data['email'])],
            ['name' => $data['name'], 'user_id' => $request->user()?->id, 'status' => 'waiting'],
        );

        return back()->with('flash_success', 'You’re on the waitlist — we’ll email you if a spot opens up.');
    }
}

<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EventReviewController extends Controller
{
    /**
     * Leave (or update) a rating + review. Only attendees — people who hold a
     * paid ticket for the event — can review, and never their own event. One
     * review per user, upserted so editing just overwrites it.
     */
    public function store(Request $request, Event $event)
    {
        $user = $request->user();

        abort_if($user->id === $event->user_id, 403, 'You cannot review your own event.');

        if (! $event->hasAttendee($user)) {
            throw ValidationException::withMessages([
                'rating' => 'Only people who got a ticket for this event can review it.',
            ]);
        }

        $data = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'body' => ['nullable', 'string', 'max:2000'],
        ]);

        $event->reviews()->updateOrCreate(['user_id' => $user->id], $data);

        return back(303)->with('success', 'Thanks for your review!');
    }
}

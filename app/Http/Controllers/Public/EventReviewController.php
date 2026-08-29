<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Event;
use Illuminate\Http\Request;

class EventReviewController extends Controller
{
    /**
     * Leave (or update) a rating + review. Any signed-in user may review, except
     * the organizer of their own event. One review per user, upserted so editing
     * just overwrites it.
     */
    public function store(Request $request, Event $event)
    {
        $user = $request->user();

        abort_if($user->id === $event->user_id, 403, 'You cannot review your own event.');

        $data = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'body' => ['nullable', 'string', 'max:2000'],
        ]);

        $event->reviews()->updateOrCreate(['user_id' => $user->id], $data);

        return back(303)->with('success', 'Thanks for your review!');
    }
}

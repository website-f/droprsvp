<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventComment;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EventCommentController extends Controller
{
    /** Post a question or reply. Premium members + the organizer only. */
    public function store(Request $request, Event $event)
    {
        $user = $request->user();
        $isOwner = $user->id === $event->user_id;

        // Gating: posting in discussions is a Premium benefit (the organizer + superadmin are exempt).
        if (! $user->hasPremiumAccess() && ! $isOwner) {
            throw ValidationException::withMessages([
                'body' => 'Posting in the discussion is a Premium benefit. Upgrade to join the conversation.',
            ]);
        }

        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
            'parent_id' => ['nullable', 'integer'],
        ]);

        // A reply must point at a top-level comment on this same event.
        $parentId = null;
        if (! empty($data['parent_id'])) {
            $parent = EventComment::where('event_id', $event->id)->whereNull('parent_id')->find($data['parent_id']);
            $parentId = $parent?->id;
        }

        EventComment::create([
            'event_id' => $event->id,
            'user_id' => $user->id,
            'parent_id' => $parentId,
            'body' => $data['body'],
        ]);

        return back()->with('success', 'Posted.');
    }
}

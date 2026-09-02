<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\User;
use Illuminate\Http\Request;

class BroadcastController extends Controller
{
    /** Send an in-app notification to every user in an audience. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'audience' => ['required', 'in:all,organizers,buyers,admins'],
            'title' => ['required', 'string', 'max:120'],
            'body' => ['nullable', 'string', 'max:500'],
            'url' => ['nullable', 'string', 'max:512'],
            'level' => ['nullable', 'in:info,success,warning'],
        ]);

        $ids = match ($data['audience']) {
            'organizers' => User::role('organizer')->pluck('id'),
            'admins' => User::role('superadmin')->pluck('id'),
            'buyers' => User::role('buyer')->pluck('id'),
            default => User::pluck('id'),
        };

        $count = AppNotification::notifyMany($ids, [
            'type' => 'broadcast',
            'title' => $data['title'],
            'body' => $data['body'] ?? null,
            'url' => $data['url'] ?? null,
            'level' => $data['level'] ?? 'info',
        ]);

        return back()->with('flash_success', "Broadcast sent to {$count} ".($count === 1 ? 'person' : 'people').'.');
    }
}

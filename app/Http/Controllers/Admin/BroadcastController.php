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

        $query = match ($data['audience']) {
            'organizers' => User::role('organizer'),
            'admins' => User::role('superadmin'),
            'buyers' => User::role('buyer'),
            default => User::query(),
        };

        // A platform broadcast is "product news" — honour each recipient's opt-out.
        // Filtered in PHP (JSON boolean comparisons aren't portable across MySQL/SQLite);
        // broadcasts are infrequent admin actions, so the extra rows are fine.
        $ids = $query->get(['id', 'notification_preferences'])
            ->filter(fn (User $u) => $u->wantsNotification('product_news'))
            ->pluck('id');

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

<?php

namespace App\Http\Controllers;

use App\Models\AppNotification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /** Recent notifications + unread count for the bell dropdown (JSON). */
    public function index(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'unread' => $user->appNotifications()->whereNull('read_at')->count(),
            'items' => $user->appNotifications()->limit(20)->get()->map(fn (AppNotification $n) => [
                'id' => $n->id,
                'type' => $n->type,
                'title' => $n->title,
                'body' => $n->body,
                'url' => $n->url,
                'level' => $n->level,
                'read' => $n->read_at !== null,
                'when' => $n->created_at?->diffForHumans(),
            ]),
        ]);
    }

    public function markAllRead(Request $request)
    {
        $request->user()->appNotifications()->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function markRead(Request $request, AppNotification $notification)
    {
        abort_unless($notification->user_id === $request->user()->id, 403);
        $notification->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }
}

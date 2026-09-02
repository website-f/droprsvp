<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FollowController extends Controller
{
    /** Follow / unfollow an organizer. */
    public function toggle(Request $request, User $organizer)
    {
        $user = $request->user();
        abort_if($user->id === $organizer->id, 422, 'You cannot follow yourself.');

        if ($user->isFollowing($organizer)) {
            $user->following()->detach($organizer->id);
        } else {
            $user->following()->attach($organizer->id);
        }

        return back(303);
    }

    /** The user's following feed — organizers they follow + their upcoming events. */
    public function index(Request $request)
    {
        $user = $request->user();
        $organizerIds = $user->following()->pluck('users.id');

        $organizers = $user->following()->withCount('followers')->orderBy('name')->get()->map(fn (User $o) => [
            'id' => $o->id,
            'slug' => $o->ensureSlug(),
            'name' => $o->name,
            'followers' => $o->followers_count,
        ]);

        $upcoming = Event::published()
            ->whereIn('user_id', $organizerIds)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '>=', now()->startOfDay()))
            ->with('user:id,name')
            ->orderByRaw('starts_at is null, starts_at asc')
            ->limit(30)
            ->get()
            ->map(fn (Event $e) => [
                'slug' => $e->slug,
                'title' => $e->title,
                'organizer' => $e->user?->name,
                'cover_image' => $e->cover_image,
                'when' => $e->starts_at?->setTimezone($e->timezone)->format('D, j M Y · g:i A'),
            ]);

        return Inertia::render('following', [
            'organizers' => $organizers,
            'upcoming' => $upcoming,
        ]);
    }
}

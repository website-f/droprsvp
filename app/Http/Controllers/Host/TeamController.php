<?php

namespace App\Http\Controllers\Host;

use App\Http\Controllers\Controller;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TeamController extends Controller
{
    /** The account owner's list of collaborators. */
    public function index(Request $request)
    {
        $members = $request->user()->teamMembers()->with('member:id,name,email')->latest()->get();

        return inertia('host/team', [
            'members' => $members->map(fn (TeamMember $m) => [
                'id' => $m->id,
                'name' => $m->member?->name,
                'email' => $m->member?->email,
                'role' => $m->role,
                'added' => optional($m->created_at)->format('j M Y'),
            ]),
        ]);
    }

    /** Add a collaborator by email — they must already have a DropRSVP account. */
    public function store(Request $request)
    {
        $owner = $request->user();
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);

        $member = User::whereRaw('LOWER(email) = ?', [mb_strtolower(trim($data['email']))])->first();

        if (! $member) {
            throw ValidationException::withMessages(['email' => 'No DropRSVP account uses that email. Ask them to sign up first.']);
        }
        if ($member->id === $owner->id) {
            throw ValidationException::withMessages(['email' => 'That’s you — you already manage your own events.']);
        }
        if ($owner->teamMembers()->where('member_id', $member->id)->exists()) {
            throw ValidationException::withMessages(['email' => 'They’re already on your team.']);
        }

        $owner->teamMembers()->create(['member_id' => $member->id, 'role' => 'manager']);

        // Grant the organizer role so they can reach the host panel (kept on removal —
        // they may host their own events).
        if (! $member->hasRole('organizer')) {
            $member->assignRole('organizer');
        }

        return back()->with('flash_success', "{$member->name} can now help manage your events.");
    }

    /** Remove a collaborator. */
    public function destroy(Request $request, TeamMember $member)
    {
        abort_unless($member->owner_id === $request->user()->id, 403);

        $member->delete();

        return back()->with('flash_success', 'Collaborator removed.');
    }
}

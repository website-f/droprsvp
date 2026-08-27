<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{
    /** Superadmin — all users, with their roles and activity. */
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        $users = User::query()
            ->withCount('events')
            ->with('roles:id,name')
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w->where('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%")))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'roles' => $u->roles->pluck('name'),
                'events' => $u->events_count,
                'is_superadmin' => $u->hasRole('superadmin'),
            ]);

        return inertia('admin/users/index', ['users' => $users, 'filters' => ['q' => $q]]);
    }

    /** Grant or revoke the superadmin role (can't change your own). */
    public function toggleSuperadmin(Request $request, User $user)
    {
        if ($user->id === $request->user()->id) {
            return back()->with('flash_error', 'You can’t change your own role.');
        }

        $user->hasRole('superadmin') ? $user->removeRole('superadmin') : $user->assignRole('superadmin');

        return back()->with('flash_success', "Updated {$user->name}’s access.");
    }
}

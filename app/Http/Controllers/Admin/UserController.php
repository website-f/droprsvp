<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Profile;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UserController extends Controller
{
    /** Superadmin — all users, filterable by role + demographics, with details. */
    public function index(Request $request)
    {
        $filters = $this->filters($request);

        $users = $this->query($filters)
            ->withCount('events')
            ->with('roles:id,name')
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (User $u) => $this->row($u));

        return inertia('admin/users/index', [
            'users' => $users,
            'filters' => $filters,
            'countries' => User::whereNotNull('country')->distinct()->orderBy('country')->pluck('country'),
            'ageBands' => Profile::AGE_BANDS,
        ]);
    }

    /** Full profile + activity for one user. */
    public function show(User $user)
    {
        $user->load('roles:id,name');
        $paidIds = \App\Models\Order::where('user_id', $user->id)->where('status', 'paid')->pluck('id');

        return inertia('admin/users/show', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'gender' => $user->gender,
                'age_band' => $user->age_band,
                'city' => $user->city,
                'country' => $user->country,
                'roles' => $user->roles->pluck('name'),
                'is_superadmin' => $user->hasRole('superadmin'),
                'profile_complete' => (bool) $user->profile_completed_at,
                'profile_completed_at' => optional($user->profile_completed_at)->format('j M Y'),
                'email_verified' => (bool) $user->email_verified_at,
                'joined' => optional($user->created_at)->format('j M Y'),
            ],
            'activity' => [
                'events' => $user->events()->count(),
                'orders' => $paidIds->count(),
                'tickets' => \App\Models\Ticket::whereIn('order_id', $paidIds)->count(),
                'spent' => (float) \App\Models\Order::whereKey($paidIds)->sum('total'),
                'followers' => $user->followers()->count(),
                'following' => $user->following()->count(),
            ],
        ]);
    }

    /** Export the current filtered set as CSV. */
    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $users = $this->query($filters)->with('roles:id,name')->orderBy('name')->get();

        return response()->streamDownload(function () use ($users) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Name', 'Email', 'Phone', 'Gender', 'Age band', 'City', 'Country', 'Roles', 'Profile complete', 'Joined']);
            foreach ($users as $u) {
                fputcsv($out, [
                    $u->name, $u->email, $u->phone, $u->gender, $u->age_band, $u->city, $u->country,
                    $u->roles->pluck('name')->join(', '),
                    $u->profile_completed_at ? 'yes' : 'no',
                    optional($u->created_at)->toDateString(),
                ]);
            }
            fclose($out);
        }, 'users-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
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

    /** Soft-delete a user (recoverable from the Archive). Can't delete yourself or another superadmin. */
    public function destroy(Request $request, User $user)
    {
        if ($user->id === $request->user()->id) {
            return back()->with('flash_error', 'You can’t delete your own account.');
        }
        if ($user->hasRole('superadmin')) {
            return back()->with('flash_error', 'Remove the superadmin role before deleting this user.');
        }

        $user->delete();

        return back()->with('flash_success', "{$user->name} moved to the Archive.");
    }

    /** @return array{q:string, role:string, country:string, age:string} */
    private function filters(Request $request): array
    {
        return [
            'q' => trim((string) $request->query('q', '')),
            'role' => in_array($request->query('role'), ['normal', 'organizer'], true) ? $request->query('role') : 'all',
            'country' => trim((string) $request->query('country', '')),
            'age' => in_array($request->query('age'), Profile::AGE_BANDS, true) ? $request->query('age') : '',
        ];
    }

    private function query(array $f)
    {
        return User::query()
            ->when($f['q'] !== '', fn ($x) => $x->where(fn ($w) => $w->where('name', 'like', "%{$f['q']}%")->orWhere('email', 'like', "%{$f['q']}%")->orWhere('phone', 'like', "%{$f['q']}%")))
            ->when($f['role'] === 'organizer', fn ($x) => $x->whereHas('roles', fn ($r) => $r->where('name', 'organizer')))
            ->when($f['role'] === 'normal', fn ($x) => $x->whereDoesntHave('roles', fn ($r) => $r->whereIn('name', ['organizer', 'superadmin'])))
            ->when($f['country'] !== '', fn ($x) => $x->where('country', $f['country']))
            ->when($f['age'] !== '', fn ($x) => $x->where('age_band', $f['age']));
    }

    private function row(User $u): array
    {
        return [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'phone' => $u->phone,
            'gender' => $u->gender,
            'age_band' => $u->age_band,
            'city' => $u->city,
            'country' => $u->country,
            'roles' => $u->roles->pluck('name'),
            'events' => $u->events_count,
            'profile_complete' => (bool) $u->profile_completed_at,
            'is_superadmin' => $u->hasRole('superadmin'),
        ];
    }
}

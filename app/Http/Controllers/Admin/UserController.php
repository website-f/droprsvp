<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\AccountStatusMail;
use App\Models\User;
use App\Support\Profile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
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
                'disabled' => $user->isDisabled(),
                'disabled_at' => optional($user->disabled_at)->format('j M Y'),
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

    /**
     * Add a user manually. Sets a random temporary password and flags the account
     * so the user is forced to set their own on first login (via EnsurePasswordSet).
     * Only a superadmin may mint staff / superadmin accounts.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:180', 'unique:users,email'],
            'role' => ['required', 'in:normal,organizer,staff,superadmin'],
        ]);

        if (in_array($data['role'], ['staff', 'superadmin'], true)) {
            abort_unless($request->user()->hasRole('superadmin'), 403);
        }

        $temp = Str::password(12, letters: true, numbers: true, symbols: false);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($temp),
        ]);
        // email_verified_at + must_set_password aren't mass-assignable — set directly.
        $user->email_verified_at = now(); // admin-created accounts are trusted
        $user->must_set_password = true;  // forced to set their own on first login
        $user->save();

        if ($data['role'] !== 'normal') {
            \Spatie\Permission\Models\Role::findOrCreate($data['role'], 'web');
            $user->assignRole($data['role']);
        }

        // Surface the temp password once so the admin can hand it over securely.
        return back()->with('flash_success', "{$user->name} added.")
            ->with('temp_credentials', ['email' => $user->email, 'password' => $temp]);
    }

    /**
     * Set a user's role (normal / organizer / staff / superadmin). Only a
     * superadmin may grant or revoke the privileged roles, and no one can change
     * their own role (a superadmin can't revoke themselves — view-only on self).
     */
    public function setRole(Request $request, User $user)
    {
        $data = $request->validate(['role' => ['required', 'in:normal,organizer,staff,superadmin']]);

        if ($user->id === $request->user()->id) {
            return back()->with('flash_error', 'You can’t change your own role.');
        }

        $privileged = in_array($data['role'], ['staff', 'superadmin'], true)
            || $user->hasRole('superadmin') || $user->hasRole('staff');
        if ($privileged && ! $request->user()->hasRole('superadmin')) {
            abort(403);
        }

        if ($data['role'] !== 'normal') {
            \Spatie\Permission\Models\Role::findOrCreate($data['role'], 'web');
        }
        $user->syncRoles($data['role'] === 'normal' ? [] : [$data['role']]);

        return back()->with('flash_success', "Updated {$user->name}’s role.");
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

    /** Disable (suspend) or re-enable a user; emails them either way. Can't disable yourself/another superadmin. */
    public function toggleDisabled(Request $request, User $user)
    {
        if ($user->id === $request->user()->id) {
            return back()->with('flash_error', 'You can’t disable your own account.');
        }
        if ($user->hasRole('superadmin')) {
            return back()->with('flash_error', 'You can’t disable another superadmin.');
        }

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $disabling = ! $user->isDisabled();
        // disabled_at is intentionally not mass-assignable — set it directly.
        $user->disabled_at = $disabling ? now() : null;
        $user->save();

        try {
            Mail::to($user->email)->send(new AccountStatusMail($user, $disabling, $data['reason'] ?? null));
        } catch (\Throwable $e) {
            report($e);
        }

        return back()->with('flash_success', $disabling ? "{$user->name} has been disabled." : "{$user->name} has been reactivated.");
    }

    /**
     * Soft-delete a user (recoverable from the Archive). Guarded: the account must
     * be disabled first (so active users aren't removed by accident), and you can't
     * delete yourself or another superadmin.
     */
    public function destroy(Request $request, User $user)
    {
        if ($user->id === $request->user()->id) {
            return back()->with('flash_error', 'You can’t delete your own account.');
        }
        if ($user->hasRole('superadmin')) {
            return back()->with('flash_error', 'Remove the superadmin role before deleting this user.');
        }
        if (! $user->isDisabled()) {
            return back()->with('flash_error', 'Disable this account first — only disabled users can be deleted.');
        }

        $user->delete();

        // Redirect to the list, never back() — deleting from the user's detail page
        // would otherwise reload the now-soft-deleted user's URL and 404.
        return redirect()->route('admin.users.index')->with('flash_success', "{$user->name} moved to the Archive.");
    }

    /** @return array{q:string, role:string, country:string, age:string} */
    private function filters(Request $request): array
    {
        return [
            'q' => trim((string) $request->query('q', '')),
            'role' => in_array($request->query('role'), ['normal', 'organizer', 'staff', 'superadmin'], true) ? $request->query('role') : 'all',
            'country' => trim((string) $request->query('country', '')),
            'age' => in_array($request->query('age'), Profile::AGE_BANDS, true) ? $request->query('age') : '',
        ];
    }

    private function query(array $f)
    {
        return User::query()
            ->when($f['q'] !== '', fn ($x) => $x->where(fn ($w) => $w->where('name', 'like', "%{$f['q']}%")->orWhere('email', 'like', "%{$f['q']}%")->orWhere('phone', 'like', "%{$f['q']}%")))
            ->when($f['role'] === 'organizer', fn ($x) => $x->whereHas('roles', fn ($r) => $r->where('name', 'organizer')))
            ->when($f['role'] === 'staff', fn ($x) => $x->whereHas('roles', fn ($r) => $r->where('name', 'staff')))
            ->when($f['role'] === 'superadmin', fn ($x) => $x->whereHas('roles', fn ($r) => $r->where('name', 'superadmin')))
            ->when($f['role'] === 'normal', fn ($x) => $x->whereDoesntHave('roles', fn ($r) => $r->whereIn('name', ['organizer', 'superadmin', 'staff'])))
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

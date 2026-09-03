<?php

namespace App\Http\Middleware;

use App\Models\MenuItem;
use App\Support\SiteContent;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
                'is_superadmin' => (bool) $request->user()?->hasRole('superadmin'),
                'is_organizer' => (bool) $request->user()?->hasAnyRole(['organizer', 'superadmin']),
                'is_premium' => (bool) $request->user()?->isPremium(),
                // Admin nav visibility: staff see only their granted sections; superadmin all.
                'is_admin' => \App\Support\RolePermissions::isAdmin($request->user()),
                'admin_sections' => \App\Support\RolePermissions::allowedSections($request->user()),
                'must_set_password' => (bool) $request->user()?->must_set_password,
                'unread_notifications' => $request->user()
                    ? \App\Models\AppNotification::where('user_id', $request->user()->id)->whereNull('read_at')->count()
                    : 0,
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            // Public site navigation (cached; edited under Admin → Menu).
            'nav' => MenuItem::header(),
            // Footer config (cached; edited under Admin → Footer).
            'footer' => SiteContent::footer(),
            // Brand logos + sizing (cached; edited under Admin → Branding).
            'branding' => SiteContent::branding(),
            // Site-wide announcement (banner / modal on public pages; cached).
            'announcement' => SiteContent::announcement(),
            'flash' => [
                'success' => $request->session()->get('success') ?? $request->session()->get('flash_success'),
                'error' => $request->session()->get('flash_error'),
                'warning' => $request->session()->get('flash_warning'),
            ],
            // Whether "Continue with Google" is available (keys configured).
            'googleAuth' => (bool) config('services.google.client_id') && (bool) config('services.google.client_secret'),
            // Cities for the header location selector (curated, small).
            'cities' => \App\Support\Cities::all(),
        ];
    }
}

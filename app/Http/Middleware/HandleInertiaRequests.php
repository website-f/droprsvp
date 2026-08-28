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
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            // Public site navigation (cached; edited under Admin → Menu).
            'nav' => MenuItem::header(),
            // Footer config (cached; edited under Admin → Footer).
            'footer' => SiteContent::footer(),
            'flash' => [
                'success' => $request->session()->get('success') ?? $request->session()->get('flash_success'),
                'error' => $request->session()->get('flash_error'),
            ],
        ];
    }
}

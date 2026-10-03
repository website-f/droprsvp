<?php

namespace App\Http\Middleware;

use App\Models\AppNotification;
use App\Models\CmsPost;
use App\Models\MenuItem;
use App\Services\Chat\Messenger;
use App\Support\Chat\PollToken;
use App\Support\Chat\Realtime;
use App\Support\Cities;
use App\Support\Edm\OrganizerRules;
use App\Support\Impersonation;
use App\Support\RolePermissions;
use App\Support\SeoManager;
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
                'is_admin' => RolePermissions::isAdmin($request->user()),
                'admin_sections' => RolePermissions::allowedSections($request->user()),
                'must_set_password' => (bool) $request->user()?->must_set_password,
                // Organizer email marketing: shown, shown locked (Premium would
                // unlock it), or hidden — per the rules a superadmin sets.
                'organizer_edm' => $request->user()?->hasAnyRole(['organizer', 'superadmin'])
                    ? OrganizerRules::navState($request->user())
                    : null,
                'unread_notifications' => $request->user()
                    ? AppNotification::where('user_id', $request->user()->id)->whereNull('read_at')->count()
                    : 0,
            ],
            // The title the SERVER put in <title>, so an in-app navigation sets
            // document.title to the same string instead of a bare record name.
            // share() runs after the controller, so the SeoManager it reads has
            // already been populated by whichever page is responding.
            // A CLOSURE, not a value: Inertia's middleware shares before the
            // controller runs, so reading SeoManager here eagerly would always
            // find it empty and hand every page the bare site name. Inertia
            // resolves closures when the response is built, which is after the
            // controller has populated it.
            'pageTitle' => fn () => app(SeoManager::class)->displayTitle(),
            // Messages badge + a token for the session-free poll, signed-in only.
            'chat' => fn () => $request->user() ? [
                'unread' => Messenger::unreadTotal($request->user()->id) + Messenger::requestCount($request->user()->id),
                'token' => PollToken::issue($request->user()->id),
                'v' => Realtime::version($request->user()->id),
                'bv' => Realtime::broadcastVersion(),
            ] : null,
            // Non-null only during a superadmin "view as" session; drives the
            // banner that says whose account this is and how to get out.
            'impersonating' => Impersonation::share($request),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            // Public site navigation (cached; edited under Admin → Menu).
            'nav' => MenuItem::header(),
            // Footer config (cached; edited under Admin → Footer).
            'footer' => SiteContent::footer(),
            // Latest posts for the footer's "From the blog" column (cached).
            'footerPosts' => CmsPost::forFooter(),
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
            'cities' => Cities::all(),
        ];
    }
}

<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;

/**
 * Per-section access control for the admin area. Superadmins always have full
 * access and exclusively control this matrix; staff ("admin") accounts see only
 * the sections a superadmin has granted their role. The matrix is stored as a
 * single setting so it's editable at runtime under Settings → User permissions.
 */
class RolePermissions
{
    /** Roles whose access is governed by the matrix (superadmin is always full). */
    public const MANAGED_ROLES = ['staff'];

    /**
     * Admin sections → [label, route-name prefixes]. The prefixes map a request's
     * route name back to its section for enforcement; list the most specific
     * prefixes so admin.site.* splits cleanly between Content and Appearance.
     *
     * @var array<string, array{label: string, prefixes: string[]}>
     */
    public const SECTIONS = [
        'overview' => ['label' => 'Overview', 'prefixes' => ['admin.overview']],
        'analytics' => ['label' => 'Analytics', 'prefixes' => ['admin.analytics']],
        'events' => ['label' => 'Events', 'prefixes' => ['admin.events']],
        'categories' => ['label' => 'Categories', 'prefixes' => ['admin.categories', 'admin.post-categories']],
        'organizers' => ['label' => 'Organizers', 'prefixes' => ['admin.organizers']],
        'users' => ['label' => 'Users', 'prefixes' => ['admin.users']],
        'payouts' => ['label' => 'Payouts', 'prefixes' => ['admin.payouts']],
        'finance' => ['label' => 'Finance', 'prefixes' => ['admin.finance']],
        'refunds' => ['label' => 'Refunds', 'prefixes' => ['admin.refunds']],
        'contact' => ['label' => 'Contact messages', 'prefixes' => ['admin.contact']],
        'archive' => ['label' => 'Archive', 'prefixes' => ['admin.archive']],
        'content' => ['label' => 'Content (CMS)', 'prefixes' => ['admin.cms', 'admin.seo', 'admin.site.legal', 'admin.site.home-seo']],
        'appearance' => ['label' => 'Appearance', 'prefixes' => ['admin.site.branding', 'admin.site.landing', 'admin.site.footer', 'admin.site.receipt']],
        'settings' => ['label' => 'Settings', 'prefixes' => ['admin.settings', 'admin.broadcast']],
    ];

    /** Sensible starting grant for a brand-new staff role (read-heavy, no money/users/settings). */
    private const STAFF_DEFAULT = ['overview', 'analytics', 'events', 'organizers', 'contact'];

    /** The stored matrix (role => [section keys]), with defaults applied. */
    public static function matrix(): array
    {
        $saved = Setting::getArray('role_permissions', []);
        $out = [];
        foreach (self::MANAGED_ROLES as $role) {
            $granted = $saved[$role] ?? self::STAFF_DEFAULT;
            // Keep only sections that still exist.
            $out[$role] = array_values(array_intersect(array_keys(self::SECTIONS), $granted));
        }

        return $out;
    }

    public static function save(array $matrix): void
    {
        $clean = [];
        foreach (self::MANAGED_ROLES as $role) {
            $granted = is_array($matrix[$role] ?? null) ? $matrix[$role] : [];
            $clean[$role] = array_values(array_intersect(array_keys(self::SECTIONS), $granted));
        }
        Setting::putArray('role_permissions', $clean);
    }

    /** Section keys a user may access (superadmin → all; staff → matrix; else none). */
    public static function allowedSections(?User $user): array
    {
        if (! $user) {
            return [];
        }
        if ($user->hasRole('superadmin')) {
            return array_keys(self::SECTIONS);
        }
        if ($user->hasRole('staff')) {
            return self::matrix()['staff'] ?? [];
        }

        return [];
    }

    public static function can(?User $user, string $section): bool
    {
        return in_array($section, self::allowedSections($user), true);
    }

    /** Any admin access at all (drives whether the admin nav shows). */
    public static function isAdmin(?User $user): bool
    {
        return (bool) $user && ($user->hasRole('superadmin') || $user->hasRole('staff'));
    }

    /** Map a route name to its section key (longest matching prefix wins). */
    public static function sectionForRoute(?string $routeName): ?string
    {
        if (! $routeName) {
            return null;
        }

        $best = null;
        $bestLen = -1;
        foreach (self::SECTIONS as $key => $def) {
            foreach ($def['prefixes'] as $prefix) {
                if (($routeName === $prefix || str_starts_with($routeName, $prefix.'.')) && strlen($prefix) > $bestLen) {
                    $best = $key;
                    $bestLen = strlen($prefix);
                }
            }
        }

        return $best;
    }

    /** For the settings UI: [key, label] for every section. */
    public static function sectionList(): array
    {
        return array_map(fn ($key, $def) => ['key' => $key, 'label' => $def['label']], array_keys(self::SECTIONS), self::SECTIONS);
    }
}

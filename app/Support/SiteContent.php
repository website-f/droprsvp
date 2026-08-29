<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Superadmin-editable site content — the toggleable landing sections and the
 * footer. Stored as JSON settings, merged over sensible defaults.
 */
class SiteContent
{
    /** Landing sections (each admin-toggleable) merged over defaults. */
    public static function landing(): array
    {
        $defaults = self::defaultLanding();
        $saved = Setting::getArray('landing_sections', []);

        foreach ($defaults as $key => $section) {
            if (isset($saved[$key]) && is_array($saved[$key])) {
                $defaults[$key] = array_replace($section, $saved[$key]);
            }
        }

        return $defaults;
    }

    /**
     * Homepage SEO. The landing page itself is premade and not content-editable —
     * the superadmin can only tune its title / description / keywords here.
     */
    public static function homeSeo(): array
    {
        $site = config('seo.site_name', 'DropRSVP');

        $defaults = [
            'title' => "{$site} — Discover events near you",
            'description' => "Find events happening near you and get tickets, or host your own on {$site}. Concerts, conferences, food festivals, workshops and community meetups.",
            'keywords' => '',
        ];

        // Only non-blank saved values override the defaults (a cleared title still
        // falls back to a sensible default rather than an empty <title>).
        $saved = array_filter(Setting::getArray('home_seo', []), fn ($v) => filled($v));

        return array_replace($defaults, $saved);
    }

    /**
     * Footer content as a Puck-shaped document (cached — shared on every page).
     * Rendered by a plain (no-Puck) renderer on the public side; authored via a
     * Puck editor in the admin.
     */
    public static function footer(): array
    {
        // v2 key: the footer moved from a columns array to a Puck document.
        return Cache::rememberForever('site.footer_v2', function () {
            $saved = Setting::getArray('footer', []);

            return ! empty($saved['content']) ? $saved : self::defaultFooter();
        });
    }

    public static function forgetFooter(): void
    {
        Cache::forget('site.footer_v2');
    }

    /**
     * Brand logos + per-surface sizing (superadmin-editable, cached — shared on
     * every page). Empty logo values fall back to the shipped defaults.
     */
    public static function branding(): array
    {
        return Cache::rememberForever('site.branding', function () {
            $defaults = self::defaultBranding();
            $saved = array_filter(Setting::getArray('branding', []), fn ($v) => $v !== null && $v !== '');

            return array_replace($defaults, $saved);
        });
    }

    public static function forgetBranding(): void
    {
        Cache::forget('site.branding');
    }

    public static function defaultBranding(): array
    {
        return [
            'logo_full' => '/logo-full.png',   // horizontal wordmark
            'logo_mark' => '/logo-mark.png',   // square mark / favicon
            'header_height' => 44,             // public site header (px)
            'sidebar_height' => 40,            // dashboard sidebar (px)
            'footer_height' => 36,             // footer brand (px)
            'auth_height' => 32,               // auth + checkout pages (px)
            'invert_dark' => true,             // monochrome-dark logo → invert on dark bg
        ];
    }

    public static function defaultLanding(): array
    {
        return [
            'organizer' => [
                'enabled' => true, // replaces the old always-on host CTA band
                'heading' => 'Hosting an event?',
                'body' => 'Create your event, sell tickets, manage seating and check guests in — all from one place. No setup fees.',
                'cta_label' => 'Create an event',
                'cta_url' => '/get-started',
                'image' => '',
            ],
            'event_time' => [
                'enabled' => true,
                'heading' => 'Find events by when',
                'items' => [
                    ['label' => 'Today', 'value' => 'today'],
                    ['label' => 'This weekend', 'value' => 'weekend'],
                    ['label' => 'This week', 'value' => 'week'],
                    ['label' => 'This month', 'value' => 'month'],
                ],
            ],
            'nearby_cities' => [
                'enabled' => true,
                'heading' => 'Popular near you',
                // City names must match the canonical Cities list so their slugs resolve.
                'cities' => ['Kuala Lumpur', 'Petaling Jaya', 'Shah Alam', 'George Town', 'Johor Bahru', 'Ipoh'],
            ],
            'featured_organizers' => [
                'enabled' => true,
                'heading' => 'Featured organizers',
                'subheading' => 'The people behind the events you love.',
            ],
        ];
    }

    /** Default footer as a Puck document (a single Footer block). */
    public static function defaultFooter(): array
    {
        return [
            'root' => (object) [],
            'content' => [[
                'type' => 'Footer',
                'props' => [
                    'id' => 'footer',
                    'tagline' => 'Find your people, fill your events. Discovery, ticketing, seating and QR check-in — all in one place.',
                    'ctaLabel' => 'Create an event',
                    'ctaUrl' => '/get-started',
                    'columns' => [
                        ['title' => 'Discover', 'links' => [
                            ['label' => 'Browse events', 'url' => '/en-my/all'],
                            ['label' => 'Blog', 'url' => '/blog'],
                            ['label' => 'Help center', 'url' => '/help'],
                        ]],
                        ['title' => 'For hosts', 'links' => [
                            ['label' => 'Create an event', 'url' => '/get-started'],
                            ['label' => 'My tickets', 'url' => '/my/tickets'],
                            ['label' => 'Log in', 'url' => '/login'],
                        ]],
                    ],
                ],
            ]],
        ];
    }
}

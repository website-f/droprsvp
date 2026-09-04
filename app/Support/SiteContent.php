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

    /** Superadmin-editable SEO for the /en-my/all browse page (blank → computed default). */
    public static function discoverSeo(): array
    {
        $saved = array_filter(Setting::getArray('discover_seo', []), fn ($v) => filled($v));

        return array_replace(['title' => '', 'description' => ''], $saved);
    }

    /**
     * Footer content as a Puck-shaped document (cached — shared on every page).
     * Rendered by a plain (no-Puck) renderer on the public side; authored via a
     * Puck editor in the admin.
     */
    public static function footer(): array
    {
        // Bumped to v3 when the legal-row / copyright / background props were added.
        return Cache::rememberForever('site.footer_v3', function () {
            $saved = Setting::getArray('footer', []);

            if (empty($saved['content'])) {
                return self::defaultFooter();
            }

            // Backfill any Footer props added after this footer was last saved (legal
            // links, copyright, support email, background) so the editor shows the
            // current defaults instead of blanks the admin would accidentally erase.
            $defaults = self::defaultFooter()['content'][0]['props'] ?? [];
            foreach ($saved['content'] as $i => $block) {
                if (($block['type'] ?? null) === 'Footer') {
                    $saved['content'][$i]['props'] = array_merge($defaults, $block['props'] ?? []);
                }
            }

            return $saved;
        });
    }

    public static function forgetFooter(): void
    {
        Cache::forget('site.footer_v3');
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

    /**
     * The site-wide announcement (a promo/ads banner or first-load modal),
     * superadmin-editable under Admin → Settings. Shared on every page; the client
     * dismisses it by `version` so an edited announcement re-shows.
     *
     * @return array{active: bool, style: string, level: string, title: string, body: string, cta_label: string, cta_url: string, version: int}
     */
    public static function announcement(): array
    {
        return Cache::rememberForever('site.announcement', function () {
            $saved = Setting::getArray('announcement', []);
            $style = $saved['style'] ?? 'banner';
            $level = $saved['level'] ?? 'info';

            return [
                'active' => (bool) ($saved['active'] ?? false),
                'style' => in_array($style, ['banner', 'modal'], true) ? $style : 'banner',
                'level' => in_array($level, ['info', 'success', 'warning'], true) ? $level : 'info',
                'title' => (string) ($saved['title'] ?? ''),
                'body' => (string) ($saved['body'] ?? ''),
                'cta_label' => (string) ($saved['cta_label'] ?? ''),
                'cta_url' => (string) ($saved['cta_url'] ?? ''),
                'version' => (int) ($saved['version'] ?? 1),
            ];
        });
    }

    public static function forgetAnnouncement(): void
    {
        Cache::forget('site.announcement');
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
            'hero' => [
                'style' => 'classic',   // classic (the built-in hero) | banners (image carousel)
                'autoplay' => true,
                'interval' => 5,         // seconds between banner slides
                'banners' => [],         // [{image, heading, subheading, cta_label, cta_url}]
            ],
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
            'contact' => [
                'enabled' => true,
                'heading' => 'Get in touch',
                'subheading' => 'Questions about tickets, your events, or working with us? Send a message and the right team will get back to you.',
            ],
            // Illustration showcase (the "gatherings" + "how it works" bands). Toggle
            // off to hide both — e.g. in favour of the SEO text block below.
            'showcase' => [
                'enabled' => true,
            ],
            // An SEO-friendly text block near the foot of the page (collapsed to a
            // teaser with "Read more"). Admin-editable copy for keywords/context.
            'seo_text' => [
                'enabled' => false,
                'heading' => '',
                'body' => '',
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
                    'supportEmail' => 'support@droprsvp.com',
                    'background' => 'muted',
                    'legalLinks' => [
                        ['label' => 'Contact', 'url' => '/contact'],
                        ['label' => 'Privacy Policy', 'url' => '/privacy-policy'],
                        ['label' => 'Terms & Conditions', 'url' => '/terms'],
                    ],
                    'copyright' => '© {year} DropRSVP. All rights reserved.',
                ],
            ]],
        ];
    }
}

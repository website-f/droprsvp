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

    /** Footer config (cached — rendered on every public page). */
    public static function footer(): array
    {
        return Cache::rememberForever('site.footer', fn () => array_replace(
            self::defaultFooter(),
            Setting::getArray('footer', []),
        ));
    }

    public static function forgetFooter(): void
    {
        Cache::forget('site.footer');
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
                'enabled' => false,
                'heading' => 'Find events by when',
                'items' => [
                    ['label' => 'Today', 'value' => 'today'],
                    ['label' => 'This weekend', 'value' => 'weekend'],
                    ['label' => 'This week', 'value' => 'week'],
                    ['label' => 'This month', 'value' => 'month'],
                ],
            ],
            'nearby_cities' => [
                'enabled' => false,
                'heading' => 'Popular near you',
                'cities' => ['Kuala Lumpur', 'Petaling Jaya', 'Shah Alam', 'Penang', 'Johor Bahru', 'Ipoh'],
            ],
        ];
    }

    public static function defaultFooter(): array
    {
        return [
            'tagline' => 'Find your people, fill your events. Discovery, ticketing, seating and QR check-in — all in one place.',
            'columns' => [
                ['title' => 'Discover', 'links' => [
                    ['label' => 'Browse events', 'url' => '/events'],
                    ['label' => 'Blog', 'url' => '/blog'],
                    ['label' => 'Help center', 'url' => '/help'],
                ]],
                ['title' => 'For hosts', 'links' => [
                    ['label' => 'Create an event', 'url' => '/get-started'],
                    ['label' => 'My tickets', 'url' => '/my/tickets'],
                    ['label' => 'Log in', 'url' => '/login'],
                ]],
            ],
            'copyright' => '© '.config('app.name').'. All rights reserved.',
        ];
    }
}

<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Server-side SEO defaults
    |--------------------------------------------------------------------------
    | Every SEO tag (title, meta, Open Graph, Twitter, canonical, robots and
    | JSON-LD) is rendered by Laravel into the HTML <head> — no Node/SSR needed.
    | These are the site-wide defaults; controllers override per page.
    */

    'site_name' => env('APP_NAME', 'DropRSVP'),

    // BCP-47 / OG locale, e.g. "en_US", "ms_MY".
    'locale' => env('SEO_LOCALE', 'en_US'),

    // Title template — {title} and {site} are replaced. Home uses {site} only.
    'title_separator' => env('SEO_TITLE_SEPARATOR', '·'),

    // Twitter/X handle for twitter:site (with @), optional.
    'twitter' => env('SEO_TWITTER'),

    // Fallback social share image (absolute URL or /path) when a page has none.
    // A branded 1200×630 default ships in /public so every share looks good.
    'default_image' => env('SEO_DEFAULT_IMAGE', '/og-default.png'),

    'organization' => [
        'name' => env('SEO_ORG_NAME', env('APP_NAME', 'DropRSVP')),
        // Absolute URL or /path to the logo used in Organization JSON-LD.
        'logo' => env('SEO_LOGO', '/logo.png'),
        // Optional social profile URLs (sameAs), comma-separated in env.
        'same_as' => array_values(array_filter(array_map('trim', explode(',', (string) env('SEO_SAME_AS', ''))))),
    ],

];

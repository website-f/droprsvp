<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        // Defaults to the app's callback route; override only if needed.
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    /*
     | Google Analytics 4.
     |
     | The measurement id used to be hard-coded in app.blade.php, which meant a
     | staging copy of the app reported into the live property. It is env now,
     | and blank simply turns the tag off.
     */
    'ga' => [
        'measurement_id' => env('GA_MEASUREMENT_ID', 'G-Q80TSQSFQ1'),
        // Measurement Protocol secret (GA → Admin → Data streams → the web
        // stream → Measurement Protocol API secrets). When set, purchases and
        // refunds are reported from the server the moment payment settles,
        // instead of relying on the buyer's browser reaching the confirmation
        // page with the tag loaded.
        'api_secret' => env('GA_API_SECRET'),
    ],

    /*
     | Microsoft Clarity (heatmaps + session replay). Blank turns the tag off.
     | Rendered on public pages only — see App\Support\Tracking.
     */
    'clarity' => [
        'project_id' => env('CLARITY_PROJECT_ID', 'yq9ifva1wn'),
    ],

    'chip' => [
        // CHIP Collect (https://docs.chip-in.asia). driver: 'chip' (real API) or
        // 'fake' (dev — instantly settles, no keys). Test vs live is decided by the
        // key itself, so there is no separate base URL. Defaults to fake until a
        // secret key is set.
        'driver' => env('CHIP_DRIVER', env('CHIP_SECRET_KEY') ? 'chip' : 'fake'),
        'secret' => env('CHIP_SECRET_KEY'),           // Bearer secret (test or live)
        'brand_id' => env('CHIP_BRAND_ID'),           // brand UUID from the CHIP portal
        'public_key' => env('CHIP_PUBLIC_KEY'),       // PEM for webhook verification (optional; else fetched from the API + cached)
        'currency' => env('CHIP_CURRENCY', 'MYR'),

        // CHIP Send — automated organizer bank payouts (separate product/keys).
        // Leave blank to keep payouts manual-only (admin marks paid).
        'send' => [
            'key' => env('CHIP_SEND_API_KEY'),
            'secret' => env('CHIP_SEND_API_SECRET'),
            'mode' => env('CHIP_SEND_MODE', 'staging'),   // staging | live
        ],
    ],

];

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

    'hitpay' => [
        // driver: 'hitpay' (real API) or 'fake' (dev — instantly settles, no keys).
        // Defaults to fake until real credentials are configured.
        'driver' => env('HITPAY_DRIVER', env('HITPAY_API_KEY') ? 'hitpay' : 'fake'),
        'api_key' => env('HITPAY_API_KEY'),
        'salt' => env('HITPAY_SALT'),                 // webhook HMAC secret
        'mode' => env('HITPAY_MODE', 'sandbox'),      // sandbox | live
        'currency' => env('HITPAY_CURRENCY', 'MYR'),
    ],

];

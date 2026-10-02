<?php

/*
|--------------------------------------------------------------------------
| EDM (email marketing)
|--------------------------------------------------------------------------
|
| Campaign mail goes through its own mailer ("edm" in config/mail.php) so it
| can leave from a separate address and, if inbox placement ever demands it,
| a separate service — switching EDM_MAILER moves campaigns without touching
| the ticket and receipt mail sent from MAIL_*.
|
| The hourly limit is the one setting that matters most on shared hosting.
| The host caps outgoing mail per domain per hour (ask them, or check WHM),
| and that cap is SHARED with tickets and receipts. Set this to about 70% of
| it so a campaign can never use up the allowance a buyer's ticket needs.
| Admin > Email marketing > Settings can override it without a deploy.
|
*/

return [

    'mailer' => env('EDM_MAILER', 'edm'),

    'from' => [
        'address' => env('EDM_FROM_ADDRESS', env('MAIL_FROM_ADDRESS', 'promo@edm.droprsvp.com')),
        'name' => env('EDM_FROM_NAME', 'DropRSVP'),
    ],

    'reply_to' => env('EDM_REPLY_TO'),

    // Conservative until the real host cap is known.
    'hourly_limit' => (int) env('EDM_HOURLY_LIMIT', 100),
    // Whether the limit was set deliberately, or is still the cautious default.
    'hourly_limit_configured' => env('EDM_HOURLY_LIMIT') !== null,

    // Shown in every footer. Commercial email must carry a postal address.
    'postal_address' => env('EDM_POSTAL_ADDRESS', ''),

    // A brand-new sending address has no reputation. Ramp up over days rather
    // than going straight to the full limit: start at this many per day and
    // double every `warmup_double_every_days` until the hourly limit binds.
    'warmup' => [
        'enabled' => (bool) env('EDM_WARMUP', true),
        'start_per_day' => (int) env('EDM_WARMUP_START', 100),
        'double_every_days' => (int) env('EDM_WARMUP_DOUBLE_DAYS', 3),
    ],

    /*
    | Bounce processing. Bounces return to the address campaigns are sent
    | from; `edm:bounces` reads that mailbox over IMAP every ten minutes. It
    | defaults to the EDM SMTP login, which on cPanel is the same mailbox, so
    | usually nothing extra is needed. Alternatively pipe the mailbox to
    | `php artisan edm:bounces --stdin` from cPanel's Forwarders.
    */
    'bounces' => [
        'enabled' => (bool) env('EDM_BOUNCES', true),
        'host' => env('EDM_BOUNCE_HOST', env('EDM_MAIL_HOST', env('MAIL_HOST'))),
        'port' => (int) env('EDM_BOUNCE_PORT', 993),
        'encryption' => env('EDM_BOUNCE_ENCRYPTION', 'ssl'),     // ssl (993) | tls (143 + STARTTLS) | none
        'username' => env('EDM_BOUNCE_USERNAME', env('EDM_MAIL_USERNAME', env('MAIL_USERNAME'))),
        'password' => env('EDM_BOUNCE_PASSWORD', env('EDM_MAIL_PASSWORD', env('MAIL_PASSWORD'))),
        'folder' => env('EDM_BOUNCE_FOLDER', 'INBOX'),
    ],

    /*
    | Deliverability checks. The sending IP is looked up on blocklists daily;
    | it defaults to the address of the EDM mail host. Set it explicitly if mail
    | leaves from a different IP (check a received email's headers). The DKIM
    | selector is the one your host publishes — cPanel uses "default".
    */
    'sending_ips' => array_values(array_filter(array_map('trim', explode(',', (string) env('EDM_SENDING_IP', ''))))),
    'dkim_selector' => env('EDM_DKIM_SELECTOR', 'default'),

    /*
    | Organizer EDM. Organizers email their own opted-in followers from the host
    | panel. Every email spends one credit: first from the free monthly
    | allowance (premium organizers), then from purchased credits. Packs are
    | bought through CHIP. All of it can be adjusted per organizer by a
    | superadmin in EDM → Organizers.
    */
    'organizers' => [
        'premium_allowance' => (int) env('EDM_PREMIUM_ALLOWANCE', 2000),   // free emails / month
        'free_allowance' => (int) env('EDM_FREE_ALLOWANCE', 0),            // for non-premium organizers
        'packs' => [
            ['key' => 'starter', 'name' => 'Starter', 'credits' => 1000, 'price' => 15.00],
            ['key' => 'growth', 'name' => 'Growth', 'credits' => 5000, 'price' => 60.00],
            ['key' => 'pro', 'name' => 'Pro', 'credits' => 20000, 'price' => 200.00],
        ],
        // Abuse guardrails: judged over the last 30 days, once at least
        // `min_sent` emails have gone out. Past any of these, the organizer's
        // sending is suspended until a superadmin reviews it.
        'guard' => [
            'min_sent' => 200,
            'bounce_rate' => 0.05,
            'unsubscribe_rate' => 0.02,
            'complaint_rate' => 0.003,
        ],
    ],

    // Stop a campaign before a blocklist stops the server: past these rates,
    // once enough mail has gone out to judge, it pauses itself.
    'auto_pause' => [
        'min_sent' => 50,
        'bounce_rate' => 0.05,
        'consecutive_failures' => 10,
    ],

];

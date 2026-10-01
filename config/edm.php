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

    // Stop a campaign before a blocklist stops the server: past these rates,
    // once enough mail has gone out to judge, it pauses itself.
    'auto_pause' => [
        'min_sent' => 50,
        'bounce_rate' => 0.05,
        'consecutive_failures' => 10,
    ],

];

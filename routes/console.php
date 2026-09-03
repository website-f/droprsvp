<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Return inventory held by abandoned (unpaid) carts. Needs the cPanel cron
// `* * * * * php artisan schedule:run` to fire (see DEPLOY.md).
Schedule::command('orders:release-stale')->everyTenMinutes()->withoutOverlapping();

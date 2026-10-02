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

// Report to GA every sale the buyer's browser did not (blocked tag, closed tab,
// paid in the bank app and never came back). Same cron. See GoogleAnalytics.
Schedule::command('analytics:sync-purchases')->everyFiveMinutes()->withoutOverlapping();

// Email campaigns: start any whose scheduled time has come and send the next
// batch, within the hourly limit (same cron). No queue worker needed — see
// App\Services\Edm\CampaignSender.
Schedule::command('edm:send')->everyMinute()->withoutOverlapping(10);

// Read bounces and spam complaints from the return mailbox: suppress dead
// addresses and count bounces per campaign (which is what lets the bounce-rate
// auto-pause work). See App\Support\Edm\Bounces.
Schedule::command('edm:bounces')->everyTenMinutes()->withoutOverlapping(15);

// Is the sending IP or domain on a blocklist? Alerts the admins if so.
Schedule::command('edm:health')->dailyAt('07:00')->withoutOverlapping();

// Take scheduled blog posts live at their publish time (same cron).
Schedule::command('posts:publish-scheduled')->everyMinute()->withoutOverlapping();

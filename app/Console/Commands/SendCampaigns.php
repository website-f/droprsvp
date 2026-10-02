<?php

namespace App\Console\Commands;

use App\Services\Edm\CampaignSender;
use App\Support\Edm\Throttle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Send the next batch of campaign email. Scheduled every minute.
 *
 * The whole EDM "queue worker": it starts scheduled campaigns whose time has
 * come and sends as many queued emails as the throttle allows this minute.
 * Nothing runs between ticks, which is what lets it live on shared hosting.
 */
class SendCampaigns extends Command
{
    protected $signature = 'edm:send {--status : Show the throttle without sending anything}';

    protected $description = 'Send queued email-marketing campaigns, within the hourly limit';

    public function handle(CampaignSender $sender): int
    {
        if ($this->option('status')) {
            foreach (Throttle::status() as $key => $value) {
                $this->line(str_pad($key, 18).': '.($value ?? 'not limiting'));
            }

            return self::SUCCESS;
        }

        $sent = $sender->dispatch();

        // A heartbeat, so the deliverability page can tell whether the
        // scheduler cron is actually running.
        Cache::forever('edm.dispatch.last', now()->toIso8601String());

        if ($sent > 0) {
            $this->info("Sent {$sent} campaign email(s).");
        }

        return self::SUCCESS;
    }
}

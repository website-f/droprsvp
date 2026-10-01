<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\GoogleAnalytics;
use App\Support\Tracking;
use Illuminate\Console\Command;

/**
 * Report to GA4 every sale the buyer's browser did not, and pass on refunds.
 * Runs every five minutes from the scheduler; see GoogleAnalytics for how it
 * stays in step with the confirmation page.
 */
class SyncGaPurchases extends Command
{
    protected $signature = 'analytics:sync-purchases
        {--dry-run : Show what would be sent without sending}
        {--validate : Check the setup against GA\'s validation server using the latest paid order}';

    protected $description = 'Report paid orders the browser did not to Google Analytics (Measurement Protocol)';

    public function handle(GoogleAnalytics $ga): int
    {
        if (! Tracking::serverSide()) {
            $this->warn('Server-side GA reporting is off: set GA_MEASUREMENT_ID and GA_API_SECRET in .env.');
            $this->line('Until then only the confirmation page reports sales, and blocked or missed ones are not recovered.');

            return self::SUCCESS;
        }

        if ($this->option('validate')) {
            $order = Order::whereNotNull('paid_at')->latest('paid_at')->first();

            if (! $order) {
                $this->warn('No paid order to validate with yet.');

                return self::SUCCESS;
            }

            $messages = $ga->validate($order);

            if ($messages === []) {
                $this->info("GA accepts the purchase payload (checked with {$order->reference}). Nothing was recorded — this used GA's validation endpoint.");

                return self::SUCCESS;
            }

            $this->error('GA rejected the payload:');
            $this->line(json_encode($messages, JSON_PRETTY_PRINT));

            return self::FAILURE;
        }

        $result = $ga->sync((bool) $this->option('dry-run'));

        $this->info($this->option('dry-run')
            ? "{$result['skipped']} sale(s) would be reported."
            : "Reported {$result['sent']} sale(s); {$result['failed']} failed (will retry); {$result['skipped']} skipped (claimed by a browser just now).");

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}

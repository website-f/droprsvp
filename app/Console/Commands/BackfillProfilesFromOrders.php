<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Support\Profile;
use Illuminate\Console\Command;

class BackfillProfilesFromOrders extends Command
{
    protected $signature = 'profiles:backfill {--dry-run : Report what would change without saving}';

    protected $description = 'Fill blank profile fields from the demographics buyers already gave at checkout';

    /**
     * Checkout has always collected gender, age band and city, but never carried
     * them onto the buyer's account — so every account that bought a ticket
     * before that was fixed still shows "—" for fields its owner did fill in.
     *
     * This walks the paid orders oldest-first and applies the same
     * blank-fields-only rule as the live sync, so a profile someone has since
     * maintained themselves is left alone and the earliest answer wins where an
     * account has several orders.
     */
    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $changed = 0;
        $scanned = 0;

        Order::query()
            ->whereNotNull('user_id')
            ->whereIn('status', ['paid', 'refunded'])
            ->with('user')
            ->orderBy('paid_at')
            ->chunkById(200, function ($orders) use (&$changed, &$scanned, $dry) {
                foreach ($orders as $order) {
                    $scanned++;
                    $user = $order->user;

                    if (! $user) {
                        continue;
                    }

                    if ($dry) {
                        // changesFromOrder() reports without writing — a dry run
                        // that saves is not a dry run.
                        $fill = Profile::changesFromOrder($order);

                        if ($fill !== []) {
                            $changed++;
                            $this->line("  would update {$user->email}: ".json_encode($fill));
                        }

                        continue;
                    }

                    Profile::syncFromOrder($order);

                    if ($user->wasChanged()) {
                        $changed++;
                    }
                }
            });

        $this->info(($dry ? 'Would update' : 'Updated')." {$changed} profile(s) from {$scanned} paid order(s).");

        return self::SUCCESS;
    }
}

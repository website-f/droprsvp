<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\CheckoutService;
use Illuminate\Console\Command;

/**
 * Return reserved stock/seats from orders that were started but never paid.
 * Checkout reserves inventory up front (so concurrent buyers can't oversell);
 * without this reaper an abandoned cart would hold that stock forever and make
 * an event look sold out with no real sales.
 */
class ReleaseStalePendingOrders extends Command
{
    protected $signature = 'orders:release-stale {--minutes=30 : Release pending orders older than this}';

    protected $description = 'Release inventory held by abandoned (unpaid) pending orders';

    public function handle(CheckoutService $checkout): int
    {
        $cutoff = now()->subMinutes((int) $this->option('minutes'));

        $released = 0;
        Order::where('status', 'pending')
            ->where('created_at', '<', $cutoff)
            ->chunkById(200, function ($orders) use ($checkout, &$released) {
                foreach ($orders as $order) {
                    $checkout->release($order); // idempotent — only acts on pending
                    $released++;
                }
            });

        $this->info("Released {$released} stale pending order(s).");

        return self::SUCCESS;
    }
}

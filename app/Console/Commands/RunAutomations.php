<?php

namespace App\Console\Commands;

use App\Services\Edm\Automations;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Enrol people into active email sequences and queue whatever is due.
 * Scheduled every five minutes; the emails then go out through edm:send.
 */
class RunAutomations extends Command
{
    protected $signature = 'edm:automations';

    protected $description = 'Run email automations: enrol new people and queue due emails';

    public function handle(): int
    {
        $r = Automations::run();
        Cache::forever('edm.automations.last', ['at' => now()->toIso8601String()] + $r);

        if (array_sum($r) > 0) {
            $this->info("Enrolled {$r['enrolled']}, queued {$r['queued']}, skipped {$r['skipped']}, exited {$r['exited']}.");
        }

        return self::SUCCESS;
    }
}

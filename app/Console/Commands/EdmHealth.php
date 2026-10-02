<?php

namespace App\Console\Commands;

use App\Support\Edm\Health\BlocklistCheck;
use App\Support\Edm\Health\DomainAuth;
use Illuminate\Console\Command;

/**
 * Daily deliverability check: SPF/DKIM/DMARC on the sending domain, and the
 * sending IP and domain against the main blocklists. Alerts the admins on a
 * listing. Also runnable by hand, or from EDM → Deliverability.
 */
class EdmHealth extends Command
{
    protected $signature = 'edm:health';

    protected $description = 'Check the EDM sending domain (SPF, DKIM, DMARC) and blocklists';

    public function handle(DomainAuth $auth, BlocklistCheck $blocklists): int
    {
        foreach ($auth->check() as $r) {
            $this->line(sprintf('%-6s %-5s %s', $r['label'], strtoupper($r['status']), $r['detail']));
        }

        $listed = 0;
        foreach ($blocklists->run() as $r) {
            $listed += $r['status'] === 'listed' ? 1 : 0;
            $this->line(sprintf('%-16s %-24s %s', $r['target'], $r['label'], strtoupper($r['status'])));
        }

        if (BlocklistCheck::sendingIps() === []) {
            $this->warn('No sending IP known: set EDM_SENDING_IP (or EDM_MAIL_HOST) to check IP blocklists.');
        }

        return $listed > 0 ? self::FAILURE : self::SUCCESS;
    }
}

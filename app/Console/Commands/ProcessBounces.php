<?php

namespace App\Console\Commands;

use App\Services\Edm\OrganizerGuard;
use App\Support\Edm\Bounces\BounceProcessor;
use App\Support\Edm\Bounces\ImapMailbox;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Read the return mailbox and act on every bounce and spam complaint in it.
 * Scheduled every ten minutes; see App\Support\Edm\Bounces.
 *
 *   php artisan edm:bounces            read the mailbox over IMAP
 *   php artisan edm:bounces --stdin    process one message piped in (cPanel
 *                                      Forwarders → "Pipe to a Program")
 */
class ProcessBounces extends Command
{
    protected $signature = 'edm:bounces
        {--stdin : Read one raw message from standard input instead of the mailbox}
        {--days=14 : How far back to look in the mailbox}
        {--limit=300 : At most this many messages per run}';

    protected $description = 'Process bounces and spam complaints from the EDM return mailbox';

    public function handle(): int
    {
        if ($this->option('stdin')) {
            $raw = stream_get_contents(STDIN);
            $summary = BounceProcessor::handle((string) $raw);
            $this->remember(['fetched' => 1] + $summary, null, 'pipe');

            return self::SUCCESS;
        }

        $c = config('edm.bounces');

        if (! ($c['enabled'] ?? false) || empty($c['host']) || empty($c['username']) || empty($c['password'])) {
            $this->warn('Bounce processing is not configured: set the EDM_MAIL_* (or EDM_BOUNCE_*) login in .env.');

            return self::SUCCESS;
        }

        $totals = ['fetched' => 0, 'bounces' => 0, 'suppressed' => 0, 'matched' => 0];
        $mailbox = null;

        try {
            $mailbox = ImapMailbox::connect((string) $c['host'], (int) $c['port'], (string) $c['encryption']);
            $mailbox->login((string) $c['username'], (string) $c['password']);
            $mailbox->select((string) $c['folder']);

            foreach (array_slice($mailbox->unprocessed((int) $this->option('days')), 0, (int) $this->option('limit')) as $uid) {
                $summary = BounceProcessor::handle($mailbox->fetch($uid));
                $mailbox->markProcessed($uid);

                $totals['fetched']++;
                foreach (['bounces', 'suppressed', 'matched'] as $k) {
                    $totals[$k] += $summary[$k];
                }
            }
        } catch (\Throwable $e) {
            $this->remember($totals, $e->getMessage(), 'imap');
            $this->error('Bounce mailbox: '.$e->getMessage());
            report($e);

            return self::FAILURE;
        } finally {
            $mailbox?->logout();
        }

        $this->remember($totals, null, 'imap');

        // Bounces and complaints move organizers' rates; judge them now.
        if ($totals['bounces'] > 0) {
            OrganizerGuard::sweep();
        }
        $this->info("Read {$totals['fetched']} message(s): {$totals['bounces']} bounce(s), {$totals['suppressed']} address(es) suppressed.");

        return self::SUCCESS;
    }

    /** The last run, for the deliverability page. */
    private function remember(array $totals, ?string $error, string $via): void
    {
        Cache::forever('edm.bounces.last', ['at' => now()->toIso8601String(), 'error' => $error, 'via' => $via] + $totals);
    }
}

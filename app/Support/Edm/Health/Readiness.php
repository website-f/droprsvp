<?php

namespace App\Support\Edm\Health;

use App\Models\EdmCheck;
use App\Models\Setting;
use App\Support\Edm\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Everything that has to be true before campaign mail lands in inboxes, as a
 * checklist the admin can work down — each item with its state and what to do.
 *
 * Built from what the app can observe: config, settings, the scheduler and
 * bounce-reader heartbeats, and the latest DNS and blocklist results.
 */
final class Readiness
{
    /**
     * @return list<array{id: string, label: string, status: string, detail: string}>
     */
    public static function checklist(): array
    {
        $items = [];
        $add = function (string $id, string $label, string $status, string $detail) use (&$items) {
            $items[] = compact('id', 'label', 'status', 'detail');
        };

        $from = (string) config('edm.from.address');
        $domain = DomainAuth::domain();
        $mainDomain = strtolower((string) substr((string) config('mail.from.address'), strrpos((string) config('mail.from.address'), '@') + 1));
        $add('from', 'Sending address', $from === '' ? 'fail' : ($domain && $domain === $mainDomain ? 'warn' : 'pass'),
            $from === '' ? 'Set EDM_FROM_ADDRESS in .env.'
                : ($domain === $mainDomain
                    ? "{$from} shares a domain with ticket mail. A sub-domain (edm.droprsvp.com) keeps campaign reputation from affecting tickets."
                    : "{$from} — on its own domain, so campaign reputation stays separate from ticket mail."));

        $mailer = 'mail.mailers.'.config('edm.mailer', 'edm');
        $transport = (string) config("{$mailer}.transport");
        $host = (string) config("{$mailer}.host");
        $add('mailer', 'Mail server', $transport === 'smtp' && ! in_array($host, ['', '127.0.0.1', 'localhost'], true) ? 'pass' : 'fail',
            $transport === 'smtp' ? ($host && ! in_array($host, ['127.0.0.1', 'localhost'], true) ? "SMTP via {$host}." : 'Set EDM_MAIL_HOST, EDM_MAIL_USERNAME and EDM_MAIL_PASSWORD in .env.')
                : "Transport is \"{$transport}\" — nothing is actually sent. Set EDM_MAIL_TRANSPORT=smtp and the EDM_MAIL_* login.");

        $saved = Setting::getArray('edm', []);
        $add('hourly', 'Hourly limit', (int) ($saved['hourly_limit'] ?? 0) > 0 || config('edm.hourly_limit_configured') ? 'pass' : 'warn',
            (int) ($saved['hourly_limit'] ?? 0) > 0 || config('edm.hourly_limit_configured')
                ? Settings::get('hourly_limit').' campaign emails an hour.'
                : 'Still the cautious default of '.Settings::get('hourly_limit').'/hour. Ask your host for the per-domain hourly cap and set about 70% of it in Settings.');

        $add('postal', 'Postal address', trim((string) Settings::get('postal_address')) !== '' ? 'pass' : 'fail',
            trim((string) Settings::get('postal_address')) !== '' ? 'Shown in every footer.' : 'Required in commercial email. Add it in Settings.');

        $last = Cache::get('edm.dispatch.last');
        $fresh = $last && Carbon::parse($last)->gt(now()->subMinutes(5));
        $add('scheduler', 'Scheduler', $fresh ? 'pass' : 'fail',
            $fresh ? 'Running — the sender last ran '.Carbon::parse($last)->diffForHumans().'.'
                : ($last ? 'Not run since '.Carbon::parse($last)->diffForHumans().'. Check the cPanel cron for "php artisan schedule:run".' : 'Never run. Add the cPanel cron: * * * * * php artisan schedule:run'));

        $b = Cache::get('edm.bounces.last');
        $c = config('edm.bounces');
        $configured = ($c['enabled'] ?? false) && ! empty($c['host']) && ! empty($c['username']) && ! empty($c['password']);
        $add('bounces', 'Bounce processing',
            ! $configured && ! $b ? 'fail' : (($b['error'] ?? null) ? 'fail' : ($b ? 'pass' : 'warn')),
            match (true) {
                ! $configured && ! $b => 'Not set up. It uses the EDM_MAIL_* login by default — set those (or EDM_BOUNCE_*) so bounces are read.',
                (bool) ($b['error'] ?? null) => 'Last run failed: '.$b['error'],
                (bool) $b => 'Last read '.Carbon::parse($b['at'])->diffForHumans().': '.($b['bounces'] ?? 0).' bounce(s).',
                default => 'Configured; waiting for its first run.',
            });

        foreach (['spf' => 'SPF record', 'dkim' => 'DKIM signing', 'dmarc' => 'DMARC policy'] as $name => $label) {
            $row = $domain ? EdmCheck::where('kind', 'dns')->where('target', $domain)->where('name', $name)->first() : null;
            $add($name, $label, $row ? ($row->status === 'pass' ? 'pass' : $row->status) : 'warn',
                $row ? (string) $row->detail : 'Not checked yet — run the check below.');
        }

        $listed = EdmCheck::where('kind', 'blocklist')->where('status', 'listed')->get();
        $checked = EdmCheck::where('kind', 'blocklist')->exists();
        $add('blocklists', 'Blocklists', ! $checked ? 'warn' : ($listed->isEmpty() ? 'pass' : 'fail'),
            ! $checked ? 'Not checked yet — run the check below.'
                : ($listed->isEmpty() ? 'Not listed anywhere we check.' : 'Listed on '.$listed->pluck('name')->implode(', ').'.'));

        return $items;
    }
}

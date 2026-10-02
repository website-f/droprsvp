<?php

namespace App\Support\Edm\Health;

use App\Models\EdmCheck;
use App\Support\PlatformAlert;

/**
 * Is the sending IP or domain on a public blocklist?
 *
 * A listing means a large share of campaign mail — and on shared hosting, the
 * tickets and receipts sent from the same server — is refused. Checked daily;
 * the admins are alerted the moment anything is listed, and again when it
 * clears.
 *
 * Spamhaus refuses queries from big public resolvers (Google, Cloudflare) and
 * answers 127.255.255.x instead; that is reported as "unknown", never as
 * "listed", so a resolver quirk cannot raise a false alarm.
 */
class BlocklistCheck
{
    /** DNS blocklists for IPs: the ones mailbox providers actually consult. */
    public const IP_LISTS = [
        'zen.spamhaus.org' => 'Spamhaus ZEN',
        'bl.spamcop.net' => 'SpamCop',
        'b.barracudacentral.org' => 'Barracuda',
        'psbl.surriel.com' => 'PSBL',
        'bl.mailspike.net' => 'Mailspike',
        'dnsbl-1.uceprotect.net' => 'UCEPROTECT L1',
    ];

    /** Domain blocklists: checked against the sending domain. */
    public const DOMAIN_LISTS = [
        'dbl.spamhaus.org' => 'Spamhaus DBL',
        'multi.surbl.org' => 'SURBL',
    ];

    public function __construct(private Dns $dns) {}

    /** The IPv4 addresses mail leaves from: configured, or the EDM mail host's. */
    public static function sendingIps(): array
    {
        $configured = (array) config('edm.sending_ips', []);

        if ($configured !== []) {
            return array_values(array_filter($configured, fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)));
        }

        $host = (string) config('mail.mailers.'.config('edm.mailer', 'edm').'.host');

        if ($host === '' || in_array($host, ['127.0.0.1', 'localhost', 'mailpit'], true)) {
            return [];
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return [$host];
        }

        return array_values(array_slice(app(Dns::class)->a($host), 0, 2));
    }

    /**
     * Run every list, record the results, and alert on any change into or out
     * of "listed".
     *
     * @return list<array{target: string, list: string, label: string, status: string, detail: ?string}>
     */
    public function run(): array
    {
        $results = [];

        foreach (self::sendingIps() as $ip) {
            $reversed = implode('.', array_reverse(explode('.', $ip)));

            foreach (self::IP_LISTS as $zone => $label) {
                $results[] = $this->lookup($ip, "{$reversed}.{$zone}", $zone, $label);
            }
        }

        if ($domain = DomainAuth::domain()) {
            $root = implode('.', array_slice(explode('.', $domain), -2));

            foreach (self::DOMAIN_LISTS as $zone => $label) {
                $results[] = $this->lookup($root, "{$root}.{$zone}", $zone, $label);
            }
        }

        $this->alert($results);

        return $results;
    }

    private function lookup(string $target, string $query, string $zone, string $label): array
    {
        $answers = $this->dns->a($query);
        $codes = implode(', ', $answers);

        if ($answers === []) {
            $status = 'clean';
            $detail = null;
        } elseif (array_filter($answers, fn ($a) => str_starts_with($a, '127.255.255.') || $a === '127.0.0.1' && str_contains($zone, 'surbl'))) {
            // Spamhaus: 127.255.255.252/254/255 = query refused or malformed.
            // SURBL: 127.0.0.1 = blocked resolver.
            $status = 'unknown';
            $detail = "The list refused the query ({$codes}). This usually means the server's DNS resolver is a public one the list blocks; it says nothing about a listing.";
        } else {
            $status = 'listed';
            $detail = "Listed (answer {$codes}). Look up {$target} on the {$label} website for the reason and the delisting form.";
        }

        $previous = EdmCheck::where('kind', 'blocklist')->where('target', $target)->where('name', $zone)->value('status');
        [$row, $changed] = EdmCheck::record('blocklist', $target, $zone, $status, $detail);

        return ['target' => $target, 'list' => $zone, 'label' => $label, 'status' => $status, 'detail' => $detail, 'changed' => $changed, 'previous' => $previous, 'since' => $row->changed_at];
    }

    private function alert(array $results): void
    {
        $listed = array_values(array_filter($results, fn ($r) => $r['status'] === 'listed'));
        $newlyListed = array_values(array_filter($listed, fn ($r) => $r['changed']));

        // Every daily run while listed, so it cannot be forgotten; louder when new.
        if ($listed !== []) {
            PlatformAlert::raise(
                type: 'edm',
                title: $newlyListed !== [] ? 'Sending server newly blocklisted' : 'Sending server still blocklisted',
                body: implode('; ', array_map(fn ($r) => "{$r['target']} on {$r['label']}", $listed)).'. Campaign and ticket email may be refused until it is delisted.',
                url: '/admin/edm/deliverability',
                details: collect($listed)->mapWithKeys(fn ($r) => ["{$r['target']} · {$r['label']}" => (string) $r['detail']])->all(),
                level: 'warning',
            );

            return;
        }

        // Good news is news too: something that was listed has cleared.
        $cleared = array_values(array_filter($results, fn ($r) => $r['previous'] === 'listed' && $r['status'] === 'clean'));

        if ($cleared !== []) {
            PlatformAlert::raise(
                type: 'edm',
                title: 'Sending server removed from blocklist',
                body: implode('; ', array_map(fn ($r) => "{$r['target']} is no longer on {$r['label']}", $cleared)).'.',
                url: '/admin/edm/deliverability',
                level: 'info',
            );
        }
    }
}

<?php

namespace App\Support\Edm\Health;

use App\Models\EdmCheck;

/**
 * Is the sending domain set up so inboxes can trust it? SPF, DKIM and DMARC,
 * read from live DNS, each with what is wrong and the record to publish.
 *
 * Since 2024 Gmail and Yahoo require all three from anyone sending in bulk;
 * without them campaign mail goes to spam or is refused outright.
 */
class DomainAuth
{
    public function __construct(private Dns $dns) {}

    /** The domain campaign mail is sent from, e.g. edm.droprsvp.com. */
    public static function domain(): ?string
    {
        $from = (string) config('edm.from.address');

        return str_contains($from, '@') ? strtolower(substr($from, strrpos($from, '@') + 1)) : null;
    }

    /**
     * @return list<array{name: string, label: string, status: string, detail: string, record: ?string, host: string, suggest: ?string}>
     */
    public function check(?string $domain = null): array
    {
        $domain ??= self::domain();

        if (! $domain) {
            return [];
        }

        $results = [$this->spf($domain), $this->dkim($domain), $this->dmarc($domain)];

        foreach ($results as $r) {
            EdmCheck::record('dns', $domain, $r['name'], $r['status'], $r['detail']);
        }

        return $results;
    }

    private function spf(string $domain): array
    {
        $records = array_values(array_filter($this->dns->txt($domain), fn ($t) => stripos($t, 'v=spf1') === 0));
        $ip = BlocklistCheck::sendingIps()[0] ?? null;
        $suggest = 'v=spf1 +a +mx'.($ip ? " +ip4:{$ip}" : '').' ~all';
        $base = ['name' => 'spf', 'label' => 'SPF', 'host' => $domain, 'suggest' => $suggest];

        if ($records === []) {
            return $base + ['status' => 'fail', 'record' => null, 'detail' => 'No SPF record. Receiving servers cannot tell which servers may send for this domain.'];
        }

        if (count($records) > 1) {
            return $base + ['status' => 'fail', 'record' => implode(' | ', $records), 'detail' => 'There are '.count($records).' SPF records. Only one is allowed — merge them, or every check fails.'];
        }

        $spf = $records[0];

        if (preg_match('/[+?]all\b/i', $spf) && ! preg_match('/[~-]all\b/i', $spf)) {
            return $base + ['status' => 'warn', 'record' => $spf, 'detail' => 'Ends in "+all" or "?all", which lets anyone send as you. Use "~all" (or "-all" once you are sure).'];
        }

        if (! preg_match('/[~-]all\b/i', $spf)) {
            return $base + ['status' => 'warn', 'record' => $spf, 'detail' => 'No closing "~all". Add it so unlisted servers are treated as suspicious.'];
        }

        if ($ip && ! str_contains($spf, $ip) && ! preg_match('/\b(\+?a|\+?mx|include:)/i', $spf)) {
            return $base + ['status' => 'warn', 'record' => $spf, 'detail' => "Does not appear to cover the sending server ({$ip})."];
        }

        return $base + ['status' => 'pass', 'record' => $spf, 'detail' => 'Published and closes with '.(preg_match('/-all/i', $spf) ? '"-all"' : '"~all"').'.'];
    }

    private function dkim(string $domain): array
    {
        $selector = (string) config('edm.dkim_selector', 'default');
        $host = "{$selector}._domainkey.{$domain}";
        $records = array_values(array_filter($this->dns->txt($host), fn ($t) => stripos($t, 'p=') !== false));
        $base = ['name' => 'dkim', 'label' => 'DKIM', 'host' => $host, 'suggest' => null];

        if ($records === []) {
            return $base + ['status' => 'fail', 'record' => null, 'detail' => "No DKIM key at {$host}. Enable DKIM for {$domain} in cPanel → Email Deliverability; it publishes the key (selector \"{$selector}\")."];
        }

        if (preg_match('/p=\s*(;|$)/', $records[0])) {
            return $base + ['status' => 'fail', 'record' => $records[0], 'detail' => 'The DKIM key is empty (revoked). Re-enable DKIM in cPanel.'];
        }

        return $base + ['status' => 'pass', 'record' => mb_strimwidth($records[0], 0, 120, '…'), 'detail' => "Key published (selector \"{$selector}\"). Mail is signed so inboxes can verify it was not altered."];
    }

    private function dmarc(string $domain): array
    {
        $host = "_dmarc.{$domain}";
        $records = array_values(array_filter($this->dns->txt($host), fn ($t) => stripos($t, 'v=DMARC1') === 0));
        $root = implode('.', array_slice(explode('.', $domain), -2));
        $suggest = "v=DMARC1; p=none; rua=mailto:dmarc@{$root}; adkim=r; aspf=r; pct=100";
        $base = ['name' => 'dmarc', 'label' => 'DMARC', 'host' => $host, 'suggest' => $suggest];

        // A sub-domain inherits the organisational domain's DMARC policy.
        if ($records === [] && $root !== $domain) {
            $inherited = array_values(array_filter($this->dns->txt("_dmarc.{$root}"), fn ($t) => stripos($t, 'v=DMARC1') === 0));

            if ($inherited !== []) {
                return $base + ['status' => 'pass', 'record' => $inherited[0], 'detail' => "No record of its own; inherits {$root}'s policy, which is enough."];
            }
        }

        if ($records === []) {
            return $base + ['status' => 'fail', 'record' => null, 'detail' => 'No DMARC record. Gmail and Yahoo require one from bulk senders.'];
        }

        $dmarc = $records[0];
        $policy = preg_match('/\bp=(\w+)/i', $dmarc, $m) ? strtolower($m[1]) : 'none';

        if ($policy === 'none') {
            return $base + ['status' => 'pass', 'record' => $dmarc, 'detail' => 'Published with p=none — meets the requirement. Move to p=quarantine once reports look clean.'];
        }

        return $base + ['status' => 'pass', 'record' => $dmarc, 'detail' => "Published with p={$policy}."];
    }
}

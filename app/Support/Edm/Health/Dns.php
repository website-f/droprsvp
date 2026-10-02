<?php

namespace App\Support\Edm\Health;

/**
 * DNS lookups for the deliverability checks, behind one class so tests can
 * swap in fixed answers instead of touching the network.
 */
class Dns
{
    /** @return list<string> TXT strings at $name (multi-part records joined). */
    public function txt(string $name): array
    {
        $records = @dns_get_record($name, DNS_TXT) ?: [];

        return array_values(array_map(
            fn ($r) => isset($r['entries']) ? implode('', $r['entries']) : (string) ($r['txt'] ?? ''),
            $records,
        ));
    }

    /** @return list<string> A-record addresses for $name. */
    public function a(string $name): array
    {
        $records = @dns_get_record($name, DNS_A) ?: [];

        return array_values(array_filter(array_map(fn ($r) => (string) ($r['ip'] ?? ''), $records)));
    }

    /** @return list<string> MX hosts for $name. */
    public function mx(string $name): array
    {
        $records = @dns_get_record($name, DNS_MX) ?: [];

        return array_values(array_map(fn ($r) => (string) ($r['target'] ?? ''), $records));
    }
}

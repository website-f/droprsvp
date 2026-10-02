<?php

namespace App\Services\Edm;

use App\Models\EdmSendingDomain;
use App\Support\Edm\Health\BlocklistCheck;
use App\Support\Edm\Health\Dns;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Mime\Crypto\DkimSigner;

/**
 * An organizer's own From domain: "hello@myband.com" instead of DropRSVP's.
 *
 * The organizer adds the domain; the app makes a DKIM key pair for it and
 * shows two DNS records to publish — an ownership token and the DKIM public
 * key. Once both are found, the domain is verified and the app signs that
 * organizer's campaign mail with the private key (d=myband.com), which is what
 * makes inboxes accept mail "from" a domain the server does not host: DMARC
 * passes on the aligned DKIM signature.
 *
 * Re-checked daily; a domain whose records disappear stops being used, and its
 * campaigns fall back to the platform address rather than sending unsigned.
 */
class SendingDomains
{
    public const SELECTOR = 'droprsvp';

    public function __construct(private Dns $dns) {}

    public function add(int $organizerId, string $domain): EdmSendingDomain
    {
        $domain = strtolower(trim($domain, " .\t\n"));
        $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;
        $domain = explode('/', $domain)[0];

        if (! preg_match('/^(?=.{4,191}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $domain)) {
            throw new RuntimeException('Enter a domain like myband.com or mail.myband.com.');
        }

        $platform = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        $root = implode('.', array_slice(explode('.', $platform), -2));

        if ($root !== '' && ($domain === $root || str_ends_with($domain, '.'.$root))) {
            throw new RuntimeException('That is a DropRSVP domain. Add a domain you own.');
        }

        if (EdmSendingDomain::where('domain', $domain)->exists()) {
            throw new RuntimeException('That domain has already been added.');
        }

        [$private, $public] = self::keyPair();

        return EdmSendingDomain::create([
            'organizer_id' => $organizerId,
            'domain' => $domain,
            'selector' => self::SELECTOR,
            'dkim_private' => $private,
            'dkim_public' => $public,
            'verify_token' => Str::random(32),
            'status' => 'pending',
        ]);
    }

    /**
     * The records to publish, with what each one is for.
     *
     * @return list<array{key: string, type: string, host: string, value: string, purpose: string, required: bool}>
     */
    public static function records(EdmSendingDomain $d): array
    {
        $ip = BlocklistCheck::sendingIps()[0] ?? null;

        return [
            ['key' => 'verify', 'type' => 'TXT', 'host' => "_droprsvp.{$d->domain}", 'value' => "droprsvp-verify={$d->verify_token}", 'purpose' => 'Proves you own the domain.', 'required' => true],
            ['key' => 'dkim', 'type' => 'TXT', 'host' => "{$d->selector}._domainkey.{$d->domain}", 'value' => "v=DKIM1; k=rsa; p={$d->dkim_public}", 'purpose' => 'Lets inboxes verify mail we send for you. Without it, mail “from” your domain is rejected.', 'required' => true],
            ['key' => 'spf', 'type' => 'TXT', 'host' => $d->domain, 'value' => 'v=spf1'.($ip ? " ip4:{$ip}" : '').' ~all', 'purpose' => 'Optional. If the domain already has an SPF record, add'.($ip ? " ip4:{$ip}" : ' our server').' to it instead of creating a second one.', 'required' => false],
            ['key' => 'dmarc', 'type' => 'TXT', 'host' => "_dmarc.{$d->domain}", 'value' => 'v=DMARC1; p=none;', 'purpose' => 'Recommended if the domain has no DMARC record yet. Gmail and Yahoo expect one.', 'required' => false],
        ];
    }

    /** Look the records up and update the domain's status. */
    public function verify(EdmSendingDomain $d): EdmSendingDomain
    {
        $token = in_array("droprsvp-verify={$d->verify_token}", array_map('trim', $this->dns->txt("_droprsvp.{$d->domain}")), true);

        $dkimRecords = $this->dns->txt("{$d->selector}._domainkey.{$d->domain}");
        $published = '';
        foreach ($dkimRecords as $r) {
            if (preg_match('/p=([A-Za-z0-9+\/=\s]+)/', $r, $m)) {
                $published = preg_replace('/\s+/', '', $m[1]) ?? '';
            }
        }
        $dkim = $published !== '' && $published === $d->dkim_public;

        $spf = collect($this->dns->txt($d->domain))->first(fn ($t) => stripos($t, 'v=spf1') === 0);
        $dmarc = collect($this->dns->txt("_dmarc.{$d->domain}"))->first(fn ($t) => stripos($t, 'v=DMARC1') === 0);

        $checks = [
            'verify' => $token ? 'pass' : 'missing',
            'dkim' => $dkim ? 'pass' : ($published !== '' ? 'mismatch' : 'missing'),
            'spf' => $spf ? 'pass' : 'missing',
            'dmarc' => $dmarc ? 'pass' : 'missing',
        ];

        $verified = $token && $dkim;
        $wasVerified = $d->isVerified();

        $d->forceFill([
            'checks' => $checks,
            'last_checked_at' => now(),
            'status' => $verified ? 'verified' : ($wasVerified || $d->status === 'failed' ? 'failed' : 'pending'),
            'verified_at' => $verified ? ($d->verified_at ?? now()) : $d->verified_at,
        ])->save();

        return $d;
    }

    /** The signer for a verified domain. */
    public static function signer(EdmSendingDomain $d): DkimSigner
    {
        return new DkimSigner($d->dkim_private, $d->domain, $d->selector);
    }

    /**
     * A new RSA-2048 key pair: [private PEM, public key base64 for DNS].
     *
     * @return array{0: string, 1: string}
     */
    public static function keyPair(): array
    {
        $config = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];

        // Windows PHP builds need an explicit openssl.cnf to generate keys.
        if (PHP_OS_FAMILY === 'Windows' && ! getenv('OPENSSL_CONF')) {
            foreach ([dirname(PHP_BINARY).'/extras/ssl/openssl.cnf', dirname(PHP_BINARY).'/openssl.cnf'] as $cnf) {
                if (is_file($cnf)) {
                    $config['config'] = $cnf;
                    break;
                }
            }
        }

        $key = openssl_pkey_new($config);

        if ($key === false) {
            throw new RuntimeException('Could not generate a DKIM key on this server ('.openssl_error_string().').');
        }

        openssl_pkey_export($key, $private, null, $config);
        $details = openssl_pkey_get_details($key);
        $public = preg_replace('/-----[^-]+-----|\s+/', '', (string) $details['key']) ?? '';

        return [(string) $private, $public];
    }
}

<?php

namespace App\Services\Payments;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;

/**
 * Airwallex credentials and switches — entered by the superadmin under
 * Settings → Airwallex, never in .env. Secrets (API key, webhook secret) are
 * stored encrypted with APP_KEY and never sent back to the browser; the form
 * only learns whether one is saved and its last four characters.
 */
final class Airwallex
{
    public const KEY = 'airwallex';

    public const BASE = [
        // As in Airwallex's API introduction (sandbox and live are separate accounts).
        'sandbox' => 'https://api.sandbox.airwallex.com',
        'production' => 'https://api.airwallex.com',
    ];

    /** Checkout Sessions need this API version or later. */
    public const API_VERSION = '2026-08-21';

    /** @return array{enabled: bool, env: string, client_id: string, account_id: string, api_key: string, webhook_secret: string, api_version: string} */
    public static function config(): array
    {
        $s = Setting::getArray(self::KEY, []);

        return [
            'enabled' => (bool) ($s['enabled'] ?? false),
            'env' => ($s['env'] ?? 'sandbox') === 'production' ? 'production' : 'sandbox',
            'client_id' => (string) ($s['client_id'] ?? ''),
            'account_id' => (string) ($s['account_id'] ?? ''),
            'api_key' => self::decrypt($s['api_key'] ?? null),
            'webhook_secret' => self::decrypt($s['webhook_secret'] ?? null),
            'api_version' => (string) (($s['api_version'] ?? '') ?: self::API_VERSION),
        ];
    }

    /** Switched on, with everything a payment needs. */
    public static function configured(): bool
    {
        $c = self::config();

        return $c['enabled'] && $c['client_id'] !== '' && $c['api_key'] !== '' && $c['account_id'] !== '';
    }

    public static function baseUrl(): string
    {
        return self::BASE[self::config()['env']];
    }

    /**
     * Save from the admin form. A blank secret keeps the saved one (the form
     * never receives it); `clear_*` removes it.
     */
    public static function save(array $input): void
    {
        $old = Setting::getArray(self::KEY, []);
        $out = [
            'enabled' => (bool) ($input['enabled'] ?? false),
            'env' => ($input['env'] ?? 'sandbox') === 'production' ? 'production' : 'sandbox',
            'client_id' => trim((string) ($input['client_id'] ?? '')),
            'account_id' => trim((string) ($input['account_id'] ?? '')),
            'api_version' => trim((string) ($input['api_version'] ?? '')),
            'api_key' => $old['api_key'] ?? null,
            'webhook_secret' => $old['webhook_secret'] ?? null,
        ];

        foreach (['api_key', 'webhook_secret'] as $secret) {
            if (! empty($input['clear_'.$secret])) {
                $out[$secret] = null;
            } elseif (filled($input[$secret] ?? null)) {
                $out[$secret] = Crypt::encryptString(trim((string) $input[$secret]));
            }
        }

        Setting::putArray(self::KEY, $out);
    }

    /** What the admin form may see: no secret ever leaves the server. */
    public static function forForm(): array
    {
        $c = self::config();

        return [
            'enabled' => $c['enabled'],
            'env' => $c['env'],
            'client_id' => $c['client_id'],
            'account_id' => $c['account_id'],
            'api_version' => (string) (Setting::getArray(self::KEY, [])['api_version'] ?? ''),
            'api_key_set' => $c['api_key'] !== '',
            'api_key_hint' => $c['api_key'] !== '' ? '••••'.substr($c['api_key'], -4) : null,
            'webhook_secret_set' => $c['webhook_secret'] !== '',
            'configured' => self::configured(),
        ];
    }

    private static function decrypt(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            return '';
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return ''; // APP_KEY changed: treat as not set rather than crash checkout
        }
    }
}

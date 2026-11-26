<?php

namespace App\Support;

use App\Models\Event;
use App\Models\Setting;
use App\Services\Payments\Airwallex;

/**
 * Fees, tax and currency per country.
 *
 * Three layers, the most specific winning field by field:
 *
 *   1. the platform default — Settings → Payments & fees / Tax, which is
 *      what Malaysia uses (and every country, until told otherwise);
 *   2. "All other countries" — one set of rates for everywhere outside
 *      Malaysia, so going abroad is one edit rather than two hundred;
 *   3. a country of its own — Singapore's GST, Indonesia's PPN… — set one
 *      at a time or applied to several countries at once (bulk).
 *
 * A blank field in a layer means "inherit". An organizer on a negotiated
 * rate (users.platform_fee_*) keeps it in every country — see PlatformFee.
 *
 * How the buyer pays follows the country too: CHIP is Malaysian (MYR only),
 * so Malaysia always uses CHIP, and other countries use Airwallex once it is
 * set up — charging in the country's own currency.
 */
final class CountryRates
{
    public const KEY = 'country_rates';

    /** The country CHIP serves. */
    public const HOME = 'MY';

    /** Overridable fields. */
    public const FIELDS = ['fee_percent', 'fee_flat', 'tax_percent', 'tax_label', 'currency', 'gateway'];

    /** Container key of the per-request memo (not a static: it would outlive the request). */
    private const MEMO = 'country-rates.memo';

    /** @return array{foreign: array<string, mixed>, countries: array<string, array<string, mixed>>} */
    public static function raw(): array
    {
        $saved = Setting::getArray(self::KEY, []);

        return [
            'foreign' => self::clean((array) ($saved['foreign'] ?? [])),
            'countries' => collect((array) ($saved['countries'] ?? []))
                ->mapWithKeys(fn ($v, $k) => [strtoupper((string) $k) => self::clean((array) $v)])
                ->filter(fn ($v, $k) => isset(RegionCatalog::COUNTRIES[$k]))
                ->sortKeys()
                ->all(),
        ];
    }

    /** @param  array{foreign?: array, countries?: array}  $rates */
    public static function save(array $rates): void
    {
        Setting::putArray(self::KEY, [
            'foreign' => self::clean((array) ($rates['foreign'] ?? [])),
            'countries' => collect((array) ($rates['countries'] ?? []))
                ->mapWithKeys(fn ($v, $k) => [strtoupper((string) $k) => self::clean((array) $v)])
                ->filter(fn ($v, $k) => isset(RegionCatalog::COUNTRIES[$k]) && $v !== [])
                ->all(),
        ]);
        self::forget();
    }

    public static function forget(): void
    {
        app()->forgetInstance(self::MEMO);
    }

    /** The platform default (layer 1). */
    public static function defaults(): array
    {
        return [
            'fee_percent' => (float) Setting::get('platform_fee_percent', config('droprsvp.platform_fee_percent')),
            'fee_flat' => (float) Setting::get('platform_fee_flat', config('droprsvp.platform_fee_flat')),
            'tax_percent' => (float) Setting::get('tax_percent', config('droprsvp.tax_percent', 0)),
            'tax_label' => (string) (Setting::get('tax_label') ?: config('droprsvp.tax_label', 'SST')),
        ];
    }

    /**
     * Everything that applies in one country.
     *
     * @return array{country: string, fee_percent: float, fee_flat: float, tax_percent: float, tax_label: string,
     *               currency: string, gateway: string, source: string}
     */
    public static function for(?string $country): array
    {
        $country = strtoupper((string) ($country ?: Regions::default()['country']));

        $memo = app()->bound(self::MEMO) ? app(self::MEMO) : [];
        if (isset($memo[$country])) {
            return $memo[$country];
        }

        $raw = self::raw();
        $own = $raw['countries'][$country] ?? [];
        $foreign = $country === self::HOME ? [] : $raw['foreign'];
        $r = array_replace(self::defaults(), $foreign, $own);

        // Malaysia is CHIP's; elsewhere Airwallex once it is set up, unless an
        // admin pinned a country to one or the other.
        $gateway = $country === self::HOME ? 'chip' : (string) ($r['gateway'] ?? 'auto');
        if ($gateway === 'auto' || ! in_array($gateway, ['chip', 'airwallex'], true)) {
            $gateway = $country !== self::HOME && Airwallex::configured() ? 'airwallex' : 'chip';
        }
        if ($gateway === 'airwallex' && ! Airwallex::configured()) {
            $gateway = 'chip';
        }

        // CHIP only takes ringgit. Airwallex charges in the country's own
        // currency unless an admin chose another.
        $currency = $gateway === 'chip'
            ? 'MYR'
            : strtoupper((string) (($r['currency'] ?? '') ?: (RegionCatalog::COUNTRIES[$country][1] ?? 'MYR')));

        $memo[$country] = [
            'country' => $country,
            'fee_percent' => (float) $r['fee_percent'],
            'fee_flat' => (float) $r['fee_flat'],
            'tax_percent' => (float) $r['tax_percent'],
            'tax_label' => (string) $r['tax_label'],
            'currency' => $currency,
            'gateway' => $gateway,
            'source' => $own !== [] ? 'country' : ($foreign !== [] ? 'foreign' : 'default'),
        ];
        app()->instance(self::MEMO, $memo);

        return $memo[$country];
    }

    /** The rates for where an event happens (its region's country). */
    public static function forEvent(?Event $event): array
    {
        return self::for(self::countryOf($event?->region));
    }

    /** "en-sg" → "SG" (also for a region that has since been switched off). */
    public static function countryOf(?string $region): string
    {
        if (! $region) {
            return Regions::default()['country'];
        }

        return Regions::find($region)['country'] ?? strtoupper(substr($region, -2));
    }

    /** Display prefix for an amount: RM for ringgit, S$ for Singapore dollars… */
    public static function symbol(?string $currency): string
    {
        return match (strtoupper((string) ($currency ?: 'MYR'))) {
            'MYR' => 'RM',
            'SGD' => 'S$',
            'USD' => 'US$',
            'AUD' => 'A$',
            'EUR' => '€',
            'GBP' => '£',
            'IDR' => 'Rp',
            'THB' => '฿',
            'PHP' => '₱',
            'VND' => '₫',
            'JPY' => '¥',
            'INR' => '₹',
            default => strtoupper((string) $currency),
        };
    }

    /** "RM 25.00", "S$ 25.00". */
    public static function money(float $amount, ?string $currency): string
    {
        return self::symbol($currency).' '.number_format($amount, 2);
    }

    /** Keep the known, non-blank fields, typed. */
    private static function clean(array $v): array
    {
        $out = [];
        foreach (['fee_percent', 'fee_flat', 'tax_percent'] as $k) {
            if (isset($v[$k]) && $v[$k] !== '' && is_numeric($v[$k])) {
                $out[$k] = round(max(0, (float) $v[$k]), 2);
            }
        }
        if (filled($v['tax_label'] ?? null)) {
            $out['tax_label'] = mb_substr(trim((string) $v['tax_label']), 0, 20);
        }
        if (filled($v['currency'] ?? null) && preg_match('/^[A-Za-z]{3}$/', (string) $v['currency'])) {
            $out['currency'] = strtoupper((string) $v['currency']);
        }
        if (in_array($v['gateway'] ?? null, ['chip', 'airwallex'], true)) {
            $out['gateway'] = $v['gateway'];
        }

        return $out;
    }
}

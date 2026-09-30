<?php

namespace App\Support;

/**
 * How a payment was actually made — the instrument behind a transaction.
 *
 * CHIP knows this (its dashboard shows "Maybank2u" or "Visa" next to every
 * purchase) but we were throwing it away at settlement, so the finance page
 * could only say an amount. This normalises CHIP's `transaction_data` into two
 * short, queryable strings we store on the row:
 *
 *   method — the RAIL: fpx, card, duitnow_qr, ewallet, bank_transfer
 *   brand  — the INSTRUMENT: maybank2u, cimb, visa, tng, …
 *
 * Two columns rather than a JSON blob because the finance ledger is a raw SQL
 * UNION across four tables, and JSON extraction is not portable across the
 * drivers we run (MySQL in production, SQLite in tests).
 */
final class PaymentMethod
{
    /** method => [label, icon, colour]. `icon` names a lucide icon on the client. */
    private const METHODS = [
        'fpx' => ['Online banking (FPX)', 'landmark', '#0f766e'],
        'card' => ['Card', 'credit-card', '#1d4ed8'],
        'duitnow_qr' => ['DuitNow QR', 'qr-code', '#b91c1c'],
        'ewallet' => ['E-wallet', 'wallet', '#7c3aed'],
        'bank_transfer' => ['Bank transfer', 'landmark', '#0f766e'],
    ];

    /**
     * brand => [label, colour]. The colour is the brand's own, used for the chip
     * when no logo file is present — see logo() for why that is the normal case.
     */
    private const BRANDS = [
        // FPX banks
        'maybank2u' => ['Maybank', '#ffc20e'],
        'cimb' => ['CIMB Bank', '#ed1c24'],
        'publicbank' => ['Public Bank', '#c8102e'],
        'rhb' => ['RHB Bank', '#00529c'],
        'hongleong' => ['Hong Leong Bank', '#00558c'],
        'ambank' => ['AmBank', '#e4002b'],
        'bankislam' => ['Bank Islam', '#00a651'],
        'bsn' => ['BSN', '#004b93'],
        'ocbc' => ['OCBC Bank', '#e4002b'],
        'uob' => ['UOB', '#005eb8'],
        'hsbc' => ['HSBC', '#db0011'],
        'affinbank' => ['Affin Bank', '#f58220'],
        'alliance' => ['Alliance Bank', '#e4002b'],
        'muamalat' => ['Bank Muamalat', '#7b2d8e'],
        'agrobank' => ['Agrobank', '#00833e'],
        'standardchartered' => ['Standard Chartered', '#0473ea'],
        'kfh' => ['Kuwait Finance House', '#00a79d'],
        'bankrakyat' => ['Bank Rakyat', '#00539f'],

        // Card schemes
        'visa' => ['Visa', '#1a1f71'],
        'mastercard' => ['Mastercard', '#eb001b'],
        'amex' => ['American Express', '#006fcf'],
        'unionpay' => ['UnionPay', '#e21836'],

        // Wallets
        'tng' => ['Touch n Go eWallet', '#0064ff'],
        'grabpay' => ['GrabPay', '#00b14f'],
        'boost' => ['Boost', '#ee2e24'],
        'shopeepay' => ['ShopeePay', '#ee4d2d'],
        'atome' => ['Atome', '#b8bd00'],
    ];

    /** Substrings that identify a rail when CHIP's own naming drifts. */
    private const RAIL_HINTS = [
        'fpx' => 'fpx',
        'duitnow' => 'duitnow_qr',
        'qr' => 'duitnow_qr',
        'card' => 'card',
        'visa' => 'card',
        'master' => 'card',
        'wallet' => 'ewallet',
        'grab' => 'ewallet',
        'tng' => 'ewallet',
        'touch' => 'ewallet',
        'boost' => 'ewallet',
        'shopee' => 'ewallet',
        'atome' => 'ewallet',
        'razer' => 'ewallet',
    ];

    /**
     * Read a CHIP purchase payload (webhook body or API response) into
     * ['method' => ?string, 'brand' => ?string].
     *
     * Deliberately forgiving: CHIP has renamed these fields before and a
     * gateway change must never be able to break settlement. Anything it can't
     * recognise comes back as nulls, and the row simply shows no method.
     */
    public static function fromChip(?array $purchase): array
    {
        $none = ['method' => null, 'brand' => null];

        if (! $purchase) {
            return $none;
        }

        $tx = $purchase['transaction_data'] ?? [];
        $tx = is_array($tx) ? $tx : [];

        $raw = self::str($tx['payment_method'] ?? $purchase['payment_method'] ?? null);

        // The instrument hides in different places depending on the rail, so
        // take the first of these that looks like something we know.
        $extra = $tx['extra'] ?? [];
        $extra = is_array($extra) ? $extra : [];

        $candidates = [
            $extra['bank'] ?? null,
            $extra['bank_code'] ?? null,
            $extra['fpx_bank'] ?? null,
            $extra['issuer'] ?? null,
            $extra['brand'] ?? null,
            $extra['card_brand'] ?? null,
            $tx['brand'] ?? null,
            $raw,
        ];

        $brand = null;

        foreach ($candidates as $candidate) {
            if ($found = self::matchBrand(self::str($candidate))) {
                $brand = $found;
                break;
            }
        }

        return ['method' => self::matchMethod($raw, $brand), 'brand' => $brand];
    }

    /**
     * Everything the interface needs to render one transaction's instrument, or
     * null when we never captured it (every row created before this shipped).
     *
     * @return array{method:string,label:string,icon:string,color:string,brand:?string,brand_label:?string,brand_color:?string,logo:?string}|null
     */
    public static function describe(?string $method, ?string $brand = null): ?array
    {
        if (! $method && ! $brand) {
            return null;
        }

        // Payouts carry the organizer's raw CHIP Send bank code rather than one
        // of our keys, so normalise anything we don't already recognise.
        if ($brand && ! isset(self::BRANDS[$brand])) {
            $brand = self::matchBrand($brand) ?? $brand;
        }

        $method = $method && isset(self::METHODS[$method])
            ? $method
            : (self::matchMethod($method, $brand) ?? 'card');

        [$label, $icon, $color] = self::METHODS[$method];

        return [
            'method' => $method,
            'label' => $label,
            'icon' => $icon,
            'color' => $color,
            'brand' => $brand,
            'brand_label' => $brand ? (self::BRANDS[$brand][0] ?? self::titleise($brand)) : null,
            'brand_color' => $brand ? (self::BRANDS[$brand][1] ?? $color) : null,
            'logo' => $brand ? self::logo($brand) : null,
        ];
    }

    /** Every brand we know, for a filter dropdown. @return array<string,string> */
    public static function brands(): array
    {
        return array_map(fn ($b) => $b[0], self::BRANDS);
    }

    /** Every rail we know. @return array<string,string> */
    public static function methods(): array
    {
        return array_map(fn ($m) => $m[0], self::METHODS);
    }

    /**
     * The brand's logo, if someone has dropped one in.
     *
     * We ship none: bank and card-scheme logos are trademarks with their own
     * usage rules, and committing them here would be someone else's decision to
     * make. Drop a file at public/img/payment/<brand>.svg (or .png/.webp) and it
     * appears automatically; until then the interface renders a colour chip
     * carrying the bank's name, which is legible either way.
     */
    private static function logo(string $brand): ?string
    {
        static $cache = [];

        if (array_key_exists($brand, $cache)) {
            return $cache[$brand];
        }

        foreach (['svg', 'png', 'webp'] as $ext) {
            if (is_file(public_path("img/payment/{$brand}.{$ext}"))) {
                return $cache[$brand] = "/img/payment/{$brand}.{$ext}";
            }
        }

        return $cache[$brand] = null;
    }

    private static function matchBrand(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        $key = preg_replace('/[^a-z0-9]/', '', strtolower($value)) ?? '';

        if ($key === '') {
            return null;
        }

        if (isset(self::BRANDS[$key])) {
            return $key;
        }

        // CHIP prefixes and suffixes freely — "fpx_maybank2u", "razer_tng",
        // "MAYBANK2U_BIZ" — so fall back to containment, longest key first so
        // "maybank2u" is not shadowed by a shorter accidental match.
        $keys = array_keys(self::BRANDS);
        usort($keys, fn ($a, $b) => strlen($b) <=> strlen($a));

        foreach ($keys as $candidate) {
            if (str_contains($key, $candidate)) {
                return $candidate;
            }
        }

        // Aliases that share no substring with their canonical key.
        // SWIFT/BIC codes, which is what CHIP Send stores for payouts.
        $swift = [
            'mbbemykl' => 'maybank2u', 'cibbmykl' => 'cimb', 'pbbemykl' => 'publicbank',
            'rhbbmykl' => 'rhb', 'hlbbmykl' => 'hongleong', 'arbkmykl' => 'ambank',
            'bimbmykl' => 'bankislam', 'bsnamykl' => 'bsn', 'ocbcmykl' => 'ocbc',
            'uovbmykl' => 'uob', 'hbmbmykl' => 'hsbc', 'phbmmykl' => 'affinbank',
            'mfbbmykl' => 'alliance', 'bmmbmykl' => 'muamalat', 'agobmykl' => 'agrobank',
            'scblmykl' => 'standardchartered', 'kfhomykl' => 'kfh', 'bkrmmykl' => 'bankrakyat',
        ];

        foreach ($swift as $code => $canonical) {
            if (str_contains($key, $code)) {
                return $canonical;
            }
        }

        return match (true) {
            str_contains($key, 'maybank') => 'maybank2u',
            str_contains($key, 'touchngo'), str_contains($key, 'tngd') => 'tng',
            str_contains($key, 'publicb') => 'publicbank',
            str_contains($key, 'rakyat') => 'bankrakyat',
            default => null,
        };
    }

    private static function matchMethod(?string $raw, ?string $brand): ?string
    {
        $key = strtolower((string) $raw);

        foreach (self::RAIL_HINTS as $hint => $method) {
            if ($key !== '' && str_contains($key, $hint)) {
                return $method;
            }
        }

        if (! $brand) {
            return $key === '' ? null : 'card';
        }

        // No usable rail string, but we recognised the instrument — infer from it.
        return match (true) {
            in_array($brand, ['visa', 'mastercard', 'amex', 'unionpay'], true) => 'card',
            in_array($brand, ['tng', 'grabpay', 'boost', 'shopeepay', 'atome'], true) => 'ewallet',
            default => 'fpx',
        };
    }

    private static function str(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function titleise(string $slug): string
    {
        return ucwords(str_replace(['_', '-'], ' ', $slug));
    }
}

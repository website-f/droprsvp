<?php

namespace App\Support;

/**
 * Malaysian banks for CHIP Send payouts. The `code` is the SWIFT/BIC that CHIP
 * expects as `bank_code` (e.g. Maybank = MBBEMYKL).
 */
class Banks
{
    public const MALAYSIA = [
        'MBBEMYKL' => 'Maybank',
        'CIBBMYKL' => 'CIMB Bank',
        'PBBEMYKL' => 'Public Bank',
        'RHBBMYKL' => 'RHB Bank',
        'HLBBMYKL' => 'Hong Leong Bank',
        'ARBKMYKL' => 'AmBank',
        'BIMBMYKL' => 'Bank Islam',
        'BMMBMYKL' => 'Bank Muamalat',
        'BKRMMYKL' => 'Bank Rakyat',
        'BSNAMYK1' => 'Bank Simpanan Nasional (BSN)',
        'OCBCMYKL' => 'OCBC Bank',
        'SCBLMYKX' => 'Standard Chartered',
        'HBMBMYKL' => 'HSBC Bank',
        'UOVBMYKL' => 'United Overseas Bank (UOB)',
        'AISLMYKL' => 'Affin Bank',
        'PHBMMYKL' => 'Alliance Bank',
        'KFHOMYKL' => 'Kuwait Finance House',
        'CITIMYKL' => 'Citibank',
    ];

    /** For an AppSelect: [{value: code, label: name}]. */
    public static function options(): array
    {
        return collect(self::MALAYSIA)->map(fn ($name, $code) => ['value' => $code, 'label' => $name])->values()->all();
    }

    public static function name(?string $code): ?string
    {
        return $code ? (self::MALAYSIA[$code] ?? $code) : null;
    }

    public static function isValid(?string $code): bool
    {
        return $code !== null && array_key_exists($code, self::MALAYSIA);
    }
}

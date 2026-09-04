<?php

namespace App\Support;

use App\Models\Setting;

/**
 * The platform fee charged to the BUYER on each ticket order (a booking fee added
 * at checkout, shown as its own line on the receipt). It's the HIGHER of a
 * percentage of the ticket spend OR a flat amount — so a cheap ticket still earns
 * a sensible minimum, and a pricey one scales up. Configurable by the superadmin
 * under Settings → Payments. One place computes it so checkout, receipts and the
 * admin books never drift.
 */
class PlatformFee
{
    public static function percent(): float
    {
        return (float) Setting::get('platform_fee_percent', config('droprsvp.platform_fee_percent'));
    }

    /** Flat minimum fee (in the platform currency). */
    public static function flat(): float
    {
        return (float) Setting::get('platform_fee_flat', config('droprsvp.platform_fee_flat'));
    }

    /**
     * The fee on a ticket-spend base — the greater of the % or the flat amount.
     * Free orders (base ≤ 0) are never charged a fee.
     */
    public static function on(float $base): float
    {
        if ($base <= 0) {
            return 0.0;
        }

        return round(max($base * self::percent() / 100, self::flat()), 2);
    }

    /** Short, human label — "the higher of 5% or RM2.00". */
    public static function label(): string
    {
        $pct = rtrim(rtrim(number_format(self::percent(), 2), '0'), '.');

        return 'the higher of '.$pct.'% or RM'.number_format(self::flat(), 2);
    }

    /**
     * Structured descriptor for the frontend.
     *
     * @return array{percent:float,flat:float,label:string}
     */
    public static function toArray(): array
    {
        return [
            'percent' => self::percent(),
            'flat' => self::flat(),
            'label' => self::label(),
        ];
    }
}

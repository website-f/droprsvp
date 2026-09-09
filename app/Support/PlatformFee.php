<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;

/**
 * The platform fee charged to the BUYER on each ticket order (a booking fee added
 * at checkout, shown as its own line on the receipt). It's the HIGHER of a
 * percentage of the ticket spend OR a flat amount — so a cheap ticket still earns
 * a sensible minimum, and a pricey one scales up. Configurable by the superadmin
 * under Settings → Payments. One place computes it so checkout, receipts and the
 * admin books never drift.
 *
 * A single organizer can be moved off the global rate: `users.platform_fee_percent`
 * / `platform_fee_flat` override it for every event they host (set from Admin →
 * Settings → Payments → per-organizer fees, or from the user's own admin page).
 * NULL on either column means "use the global value" for that half. Pass the
 * event's owner to every method below to get the rate that actually applies; pass
 * nothing to read the platform-wide default.
 */
class PlatformFee
{
    public static function percent(?User $organizer = null): float
    {
        $override = $organizer?->platform_fee_percent;

        return $override !== null
            ? (float) $override
            : (float) Setting::get('platform_fee_percent', config('droprsvp.platform_fee_percent'));
    }

    /** Flat minimum fee (in the platform currency). */
    public static function flat(?User $organizer = null): float
    {
        $override = $organizer?->platform_fee_flat;

        return $override !== null
            ? (float) $override
            : (float) Setting::get('platform_fee_flat', config('droprsvp.platform_fee_flat'));
    }

    /**
     * The fee on a ticket-spend base — the greater of the % or the flat amount.
     * Free orders (base ≤ 0) are never charged a fee.
     */
    public static function on(float $base, ?User $organizer = null): float
    {
        if ($base <= 0) {
            return 0.0;
        }

        return round(max($base * self::percent($organizer) / 100, self::flat($organizer)), 2);
    }

    /** Short, human label — "the higher of 5% or RM2.00". */
    public static function label(?User $organizer = null): string
    {
        $pct = rtrim(rtrim(number_format(self::percent($organizer), 2), '0'), '.');

        return 'the higher of '.$pct.'% or RM'.number_format(self::flat($organizer), 2);
    }

    /** Whether this organizer is billed at a rate of their own rather than the global one. */
    public static function isCustom(?User $organizer): bool
    {
        return $organizer !== null
            && ($organizer->platform_fee_percent !== null || $organizer->platform_fee_flat !== null);
    }

    /**
     * Structured descriptor for the frontend. With an organizer it describes the
     * rate that applies to THEM (and flags whether it's a custom one).
     *
     * @return array{percent:float,flat:float,label:string,custom:bool}
     */
    public static function toArray(?User $organizer = null): array
    {
        return [
            'percent' => self::percent($organizer),
            'flat' => self::flat($organizer),
            'label' => self::label($organizer),
            'custom' => self::isCustom($organizer),
        ];
    }
}

<?php

namespace App\Support;

use App\Models\Setting;

/**
 * The platform's take-rate on an organizer's gross ticket revenue. Configurable
 * (superadmin, under Settings → Payments) as EITHER a percentage of gross, OR a
 * flat currency amount charged per paid order. One place computes it so the
 * organizer balance, admin overview and "heads-up" copy never drift apart.
 */
class PlatformFee
{
    /** @return 'percent'|'fixed' */
    public static function type(): string
    {
        return Setting::get('platform_fee_type', config('droprsvp.platform_fee_type')) === 'fixed' ? 'fixed' : 'percent';
    }

    public static function percent(): float
    {
        return (float) Setting::get('platform_fee_percent', config('droprsvp.platform_fee_percent'));
    }

    /** Flat amount (in the platform currency) charged per paid order. */
    public static function fixed(): float
    {
        return (float) Setting::get('platform_fee_fixed', config('droprsvp.platform_fee_fixed'));
    }

    /**
     * The fee charged on `gross` revenue that came from `orders` paid orders.
     * A fixed fee is capped at gross so an organizer's net can never go negative.
     */
    public static function on(float $gross, int $orders): float
    {
        if (self::type() === 'fixed') {
            return round(min($gross, self::fixed() * max(0, $orders)), 2);
        }

        return round($gross * self::percent() / 100, 2);
    }

    /** Short, human label — "5%" or "RM2.00 per order". */
    public static function label(): string
    {
        if (self::type() === 'fixed') {
            return 'RM'.number_format(self::fixed(), 2).' per order';
        }

        // Trim trailing zeros so 5.00 reads as "5%" but 8.50 stays "8.5%".
        return rtrim(rtrim(number_format(self::percent(), 2), '0'), '.').'%';
    }

    /**
     * Structured descriptor for the frontend.
     *
     * @return array{type:string,percent:float,fixed:float,label:string}
     */
    public static function toArray(): array
    {
        return [
            'type' => self::type(),
            'percent' => self::percent(),
            'fixed' => self::fixed(),
            'label' => self::label(),
        ];
    }
}

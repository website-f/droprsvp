<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;

/**
 * The platform's commission on each ticket order — the HIGHER of a percentage of
 * the ticket spend OR a flat amount, so a cheap ticket still earns a sensible
 * minimum and a pricey one scales up. Configurable by the superadmin under
 * Settings → Payments. One place computes it so checkout, receipts, payouts and
 * the admin books never drift.
 *
 * The ORGANIZER pays it, not the buyer. A buyer pays the ticket price (plus tax
 * where it applies) and nothing else — there is no booking fee line at checkout.
 * The commission comes out of the organizer's takings instead: PayoutService
 * settles `total - fees`, so `fees` is simply never paid out to them.
 *
 * That makes the fee a floor under the ticket price. On a flat RM3 fee an RM2
 * ticket would pay the organizer MINUS one ringgit, so minimumTicketPrice()
 * below is enforced when a paid ticket type is saved — see
 * Host\EventController. The floor is per-organizer, because a custom rate
 * changes it.
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
    /**
     * $country (ISO-2) applies that country's rate — Settings → Country rates.
     * Without one, the platform default. An organizer's own rate wins in
     * every country.
     */
    public static function percent(?User $organizer = null, ?string $country = null): float
    {
        $override = $organizer?->platform_fee_percent;

        if ($override !== null) {
            return (float) $override;
        }

        return $country
            ? CountryRates::for($country)['fee_percent']
            : (float) Setting::get('platform_fee_percent', config('droprsvp.platform_fee_percent'));
    }

    /** Flat minimum fee (in the country's currency). */
    public static function flat(?User $organizer = null, ?string $country = null): float
    {
        $override = $organizer?->platform_fee_flat;

        if ($override !== null) {
            return (float) $override;
        }

        return $country
            ? CountryRates::for($country)['fee_flat']
            : (float) Setting::get('platform_fee_flat', config('droprsvp.platform_fee_flat'));
    }

    /**
     * The fee on a ticket-spend base — the greater of the % or the flat amount.
     * Free orders (base ≤ 0) never carry a fee.
     */
    public static function on(float $base, ?User $organizer = null, ?string $country = null): float
    {
        if ($base <= 0) {
            return 0.0;
        }

        return round(max($base * self::percent($organizer, $country) / 100, self::flat($organizer, $country)), 2);
    }

    /**
     * The cheapest a PAID ticket may be sold for without the commission
     * exceeding it and paying the organizer a negative amount.
     *
     * fee = max(price × pct/100, flat). While the flat amount is the larger of
     * the two the organizer nets `price - flat`, so the binding constraint is
     * simply the flat fee: below it they lose money on every sale, and at it
     * they break even. (The percentage half can never overtake the price unless
     * the rate is above 100%, which the settings screen does not allow.)
     */
    public static function minimumTicketPrice(?User $organizer = null, ?string $country = null): float
    {
        return round(self::flat($organizer, $country), 2);
    }

    /** What the organizer actually keeps on a single ticket at this price. */
    public static function organizerNet(float $price, ?User $organizer = null, ?string $country = null): float
    {
        return round($price - self::on($price, $organizer, $country), 2);
    }

    /** Short, human label — "the higher of 5% or RM2.00" (S$2.00 in Singapore…). */
    public static function label(?User $organizer = null, ?string $country = null): string
    {
        $pct = rtrim(rtrim(number_format(self::percent($organizer, $country), 2), '0'), '.');
        $symbol = CountryRates::symbol($country ? CountryRates::for($country)['currency'] : 'MYR');

        return 'the higher of '.$pct.'% or '.$symbol.number_format(self::flat($organizer, $country), 2);
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
     * @return array{percent:float,flat:float,label:string,custom:bool,min_ticket_price:float}
     */
    public static function toArray(?User $organizer = null, ?string $country = null): array
    {
        return [
            'percent' => self::percent($organizer, $country),
            'flat' => self::flat($organizer, $country),
            'label' => self::label($organizer, $country),
            'custom' => self::isCustom($organizer),
            // The event builder needs this to stop an organizer pricing a ticket
            // below the commission it will carry.
            'min_ticket_price' => self::minimumTicketPrice($organizer, $country),
            'currency' => $country ? CountryRates::for($country)['currency'] : 'MYR',
        ];
    }
}

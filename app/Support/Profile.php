<?php

namespace App\Support;

use App\Models\Order;

/** Shared demographic option lists for the "about you" profile + admin filters. */
class Profile
{
    public const GENDERS = ['female', 'male', 'other', 'na'];

    public const AGE_BANDS = ['under-18', '18-24', '25-34', '35-44', '45-54', '55+'];

    public const COUNTRIES = [
        'Malaysia', 'Singapore', 'Indonesia', 'Thailand', 'Philippines', 'Vietnam', 'Brunei', 'Cambodia',
        'India', 'China', 'Japan', 'South Korea', 'Hong Kong', 'Taiwan', 'Australia', 'New Zealand',
        'United Kingdom', 'United States', 'Canada', 'United Arab Emirates', 'Saudi Arabia', 'Other',
    ];

    /** The oldest birth year the form will accept — a sane floor, not a rule. */
    public const EARLIEST_BIRTH_YEAR = 1920;

    /**
     * The age band someone born in $year falls into today.
     *
     * The band is still what analytics and the admin filters group by; it is now
     * derived rather than asked for, so it can never drift out of date the way a
     * self-selected band does after a birthday.
     */
    public static function bandFor(?int $year): ?string
    {
        if (! $year || $year < self::EARLIEST_BIRTH_YEAR || $year > (int) date('Y')) {
            return null;
        }

        $age = (int) date('Y') - $year;

        return match (true) {
            $age < 18 => 'under-18',
            $age < 25 => '18-24',
            $age < 35 => '25-34',
            $age < 45 => '35-44',
            $age < 55 => '45-54',
            default => '55+',
        };
    }

    /** Selectable birth years, newest first. */
    public static function birthYears(): array
    {
        return range((int) date('Y'), self::EARLIEST_BIRTH_YEAR);
    }

    /**
     * How long someone has been on the platform, as a phrase.
     *
     * Shown beside their details so an admin can see at a glance whether they
     * are a regular or signed up this morning.
     */
    public static function membershipLabel(?\DateTimeInterface $joined): ?string
    {
        if (! $joined) {
            return null;
        }

        // A real calendar diff, not seconds over an average month length: at
        // 30.44 days a month, a full year came out as "11 months".
        $diff = (new \DateTimeImmutable())->diff($joined);
        $months = ($diff->y * 12) + $diff->m;

        if ($months < 1) {
            return 'Joined this month';
        }

        if ($months < 12) {
            return $months.' month'.($months === 1 ? '' : 's').' on DropRSVP';
        }

        return $diff->y.' year'.($diff->y === 1 ? '' : 's').' on DropRSVP';
    }

    /** Everything the "about you" form insists on before a profile counts as done. */
    private const REQUIRED = ['phone', 'gender', 'age_band', 'country'];

    /**
     * Carry a settled order's buyer details onto the account behind it.
     *
     * Checkout asks the same demographics as the "about you" profile, but until
     * now the answers only ever landed on the ORDER — so a buyer who filled in
     * their gender and age band at checkout still saw "—" on their profile and
     * was told it was incomplete. Guest accounts got name, email and phone from
     * provisionBuyerAccount() and nothing else, which is why phone was the one
     * field that ever showed up.
     *
     * Only BLANK columns are filled. A profile the user maintained themselves is
     * the more deliberate record of the two, and a later checkout must not
     * quietly overwrite it — someone who moves city and updates their profile
     * would otherwise be reverted by their next ticket purchase.
     */
    public static function syncFromOrder(Order $order): void
    {
        $user = $order->user;
        $fill = self::changesFromOrder($order);

        if (! $user || $fill === []) {
            return;
        }

        $user->fill($fill);

        // Flip the "incomplete profile" flag only once everything the about-you
        // form requires is actually present. Checkout never asks for country, so
        // this usually completes only for a returning buyer who already has one —
        // the rest still see the form, now pre-filled down to a single question.
        if ($user->profile_completed_at === null && self::isComplete($user)) {
            $user->profile_completed_at = now();
        }

        $user->save();
    }

    /**
     * What syncFromOrder WOULD write, without writing it.
     *
     * Split out so `profiles:backfill --dry-run` can report the change set
     * without touching the database — a dry run that saves is not a dry run.
     *
     * @return array<string,string>  column => value, blank columns only
     */
    public static function changesFromOrder(Order $order): array
    {
        $user = $order->user;

        if (! $user) {
            return []; // a guest order with no account behind it
        }

        $candidates = [
            'phone' => $order->buyer_phone,
            'gender' => $order->buyer_gender,
            // Both: the year is what the buyer gave, the band is derived from it
            // and is what reporting groups by.
            'birth_year' => $order->buyer_birth_year,
            'age_band' => $order->buyer_age_band,
            'city' => $order->buyer_city,
        ];

        // "na" is the checkout form's PRE-SELECTED gender, so storing it would
        // record "prefer not to say" for everyone who simply never touched the
        // field. Someone who means it can still choose it on the profile form.
        if ($candidates['gender'] === 'na') {
            unset($candidates['gender']);
        }

        $fill = [];

        foreach ($candidates as $column => $value) {
            if (filled($value) && blank($user->{$column})) {
                $fill[$column] = $value;
            }
        }

        return $fill;
    }

    /** Are all the required profile columns populated on this user? */
    public static function isComplete(\App\Models\User $user): bool
    {
        foreach (self::REQUIRED as $column) {
            if (blank($user->{$column})) {
                return false;
            }
        }

        return true;
    }
}

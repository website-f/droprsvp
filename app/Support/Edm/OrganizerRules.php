<?php

namespace App\Support\Edm;

use App\Models\EdmAccount;
use App\Models\EmailCampaign;
use App\Models\EmailSend;
use App\Models\Setting;
use App\Models\User;
use App\Support\Dates;
use Illuminate\Support\Carbon;

/**
 * The rules for organizer email marketing, set by a superadmin in
 * EDM → Organizer rules, with per-organizer overrides in EDM → Organizers.
 *
 * Organizers send through DropRSVP's own mail setup, so everything they do
 * spends the platform's reputation and its host's hourly mail cap. These
 * rules are how the platform decides who may send, how much, and how often.
 *
 *  - Access: on for every approved organizer, Premium organizers only, or only
 *    those switched on one by one. A master switch turns it off for everyone,
 *    and any single organizer can be switched on or off regardless of mode.
 *  - Volume: emails per hour, per day and per week, per organizer. Sending is
 *    PACED to these (a campaign keeps going in the next window), not refused.
 *  - Size and frequency: recipients per campaign; campaigns per day and per
 *    week; and emails one person may receive from one organizer in a week.
 *  - Features: automations, the abandoned-checkout reminder (it mails people
 *    who did NOT buy, so it is off for organizers unless allowed), and
 *    sending from their own domain.
 *  - Money: the free monthly allowance and the credit packs.
 *  - Guardrails: the rates that suspend an organizer automatically.
 *
 * A limit of 0 means "no limit of this kind". A per-organizer value that is
 * not set means "use the global rule".
 */
final class OrganizerRules
{
    /** Limits that can be overridden per organizer: key => [default, max]. */
    public const LIMITS = [
        'hourly_limit' => [200, 1000000],
        'daily_limit' => [2000, 10000000],
        'weekly_limit' => [10000, 10000000],
        'max_recipients' => [5000, 10000000],
        'campaigns_per_day' => [1, 1000],
        'campaigns_per_week' => [3, 1000],
        'per_person_per_week' => [2, 100],
    ];

    /** Feature switches that can be overridden per organizer. */
    public const FEATURES = [
        'automations' => true,
        'abandoned_checkout' => false,
        'domains' => true,
    ];

    public const ACCESS_MODES = ['all', 'premium', 'selected'];

    // ---- reading ----------------------------------------------------------------

    /** The global rules, with config/edm.php as the fallback for anything unsaved. */
    public static function global(): array
    {
        $saved = Setting::getArray('edm_organizers', []);
        $cfg = (array) config('edm.organizers', []);

        $out = [
            'enabled' => (bool) ($saved['enabled'] ?? true),
            'access' => in_array($saved['access'] ?? null, self::ACCESS_MODES, true) ? $saved['access'] : 'all',
        ];

        foreach (self::LIMITS as $key => [$default, $max]) {
            $out[$key] = self::int($saved[$key] ?? null, $default, $max);
        }

        foreach (self::FEATURES as $key => $default) {
            $out[$key] = (bool) ($saved[$key] ?? $default);
        }

        $out['premium_allowance'] = self::int($saved['premium_allowance'] ?? null, (int) ($cfg['premium_allowance'] ?? 2000), 10000000);
        $out['free_allowance'] = self::int($saved['free_allowance'] ?? null, (int) ($cfg['free_allowance'] ?? 0), 10000000);
        $out['packs'] = self::packs($saved['packs'] ?? null, (array) ($cfg['packs'] ?? []));

        $guard = (array) ($cfg['guard'] ?? []);
        $out['guard'] = [
            'min_sent' => self::int($saved['guard']['min_sent'] ?? null, (int) ($guard['min_sent'] ?? 200), 1000000),
            'bounce_rate' => self::rate($saved['guard']['bounce_rate'] ?? null, (float) ($guard['bounce_rate'] ?? 0.05)),
            'unsubscribe_rate' => self::rate($saved['guard']['unsubscribe_rate'] ?? null, (float) ($guard['unsubscribe_rate'] ?? 0.02)),
            'complaint_rate' => self::rate($saved['guard']['complaint_rate'] ?? null, (float) ($guard['complaint_rate'] ?? 0.003)),
        ];

        return $out;
    }

    /** One organizer's rules: the global ones with their overrides applied. */
    public static function for(int $organizerId): array
    {
        $rules = self::global();
        $own = self::stored($organizerId);

        foreach (self::LIMITS as $key => [, $max]) {
            if (isset($own[$key]) && is_numeric($own[$key])) {
                $rules[$key] = max(0, min($max, (int) $own[$key]));
            }
        }

        foreach (array_keys(self::FEATURES) as $key) {
            if (isset($own[$key]) && is_bool($own[$key])) {
                $rules[$key] = $own[$key];
            }
        }

        return $rules;
    }

    /** Only what this organizer overrides, for the admin form. */
    public static function overrides(int $organizerId): array
    {
        return array_intersect_key(self::stored($organizerId), self::LIMITS + self::FEATURES);
    }

    /** The overrides as saved (value() would skip the model's JSON cast). */
    private static function stored(int $organizerId): array
    {
        return (array) (EdmAccount::where('organizer_id', $organizerId)->first(['rules'])?->rules ?? []);
    }

    // ---- access -------------------------------------------------------------------

    /**
     * Whether this organizer may use email marketing, and if not, why and
     * whether going Premium would change that.
     *
     * @return array{allowed: bool, reason: ?string, upgrade: bool}
     */
    public static function access(User $organizer): array
    {
        $g = self::global();
        $override = EdmAccount::where('organizer_id', $organizer->id)->value('access') ?? 'inherit';
        $no = fn (string $reason, bool $upgrade = false) => ['allowed' => false, 'reason' => $reason, 'upgrade' => $upgrade];

        // The master switch beats everything: it is how the platform stops all
        // organizer mail at once (a deliverability incident, a host warning).
        // DropRSVP's own staff always can (they run the platform's EDM anyway).
        if ($organizer->hasRole('superadmin')) {
            return ['allowed' => true, 'reason' => null, 'upgrade' => false];
        }

        if (! $g['enabled']) {
            return $no('Email marketing for organizers is switched off on DropRSVP right now.');
        }

        if ($override === 'disabled') {
            return $no('Email marketing has been switched off for your account. Contact DropRSVP if you think this is a mistake.');
        }

        if ($override === 'enabled' || $g['access'] === 'all') {
            return ['allowed' => true, 'reason' => null, 'upgrade' => false];
        }

        if ($g['access'] === 'premium') {
            return $organizer->isPremium()
                ? ['allowed' => true, 'reason' => null, 'upgrade' => false]
                : $no('Email marketing is part of DropRSVP Premium.', upgrade: true);
        }

        return $no('Email marketing is available by invitation. Contact DropRSVP to ask for access.');
    }

    public static function allowed(User|int $organizer): bool
    {
        $user = $organizer instanceof User ? $organizer : User::find($organizer);

        return $user !== null && self::access($user)['allowed'];
    }

    /** What the sidebar shows: the group, a locked group (to upsell), or nothing. */
    public static function navState(User $organizer): array
    {
        $access = self::access($organizer);
        $rules = $access['allowed'] ? self::for($organizer->id) : self::global();

        return [
            'state' => $access['allowed'] ? 'on' : ($access['upgrade'] ? 'locked' : 'off'),
            'automations' => $access['allowed'] && $rules['automations'],
            'domains' => $access['allowed'] && $rules['domains'],
        ];
    }

    // ---- usage ----------------------------------------------------------------------

    /** Emails this organizer has sent since a moment. */
    public static function sentSince(int $organizerId, \DateTimeInterface $since): int
    {
        return EmailSend::query()
            ->whereNotNull('sent_at')
            ->where('sent_at', '>=', $since)
            ->whereIn('campaign_id', EmailCampaign::where('organizer_id', $organizerId)->select('id'))
            ->count();
    }

    /** Campaigns (not automation steps) this organizer started since a moment. */
    public static function campaignsSince(int $organizerId, \DateTimeInterface $since): int
    {
        return EmailCampaign::where('organizer_id', $organizerId)
            ->where('kind', 'standard')
            ->whereNotNull('started_at')
            ->where('started_at', '>=', $since)
            ->count();
    }

    /**
     * Where an organizer stands against every volume and frequency limit.
     *
     * @return array<string, array{used: int, limit: int}>
     */
    public static function usage(int $organizerId, ?array $rules = null): array
    {
        $rules ??= self::for($organizerId);

        return [
            'hour' => ['used' => self::sentSince($organizerId, now()->subHour()), 'limit' => $rules['hourly_limit']],
            'day' => ['used' => self::sentSince($organizerId, now()->subDay()), 'limit' => $rules['daily_limit']],
            'week' => ['used' => self::sentSince($organizerId, now()->subWeek()), 'limit' => $rules['weekly_limit']],
            'campaigns_day' => ['used' => self::campaignsSince($organizerId, now()->subDay()), 'limit' => $rules['campaigns_per_day']],
            'campaigns_week' => ['used' => self::campaignsSince($organizerId, now()->subWeek()), 'limit' => $rules['campaigns_per_week']],
        ];
    }

    /**
     * How many more emails this organizer may send right now, across the
     * hourly, daily and weekly limits. Null when none of them applies.
     */
    public static function remaining(int $organizerId): ?int
    {
        $rules = self::for($organizerId);
        $left = null;

        foreach (['hourly_limit' => now()->subHour(), 'daily_limit' => now()->subDay(), 'weekly_limit' => now()->subWeek()] as $key => $since) {
            if ($rules[$key] > 0) {
                $room = $rules[$key] - self::sentSince($organizerId, $since);
                $left = $left === null ? $room : min($left, $room);
            }
        }

        return $left === null ? null : max(0, $left);
    }

    /**
     * Why this organizer may not start a campaign of this size right now, or
     * null if they may.
     */
    public static function startBlocker(int $organizerId, int $recipients): ?string
    {
        $rules = self::for($organizerId);

        if ($rules['max_recipients'] > 0 && $recipients > $rules['max_recipients']) {
            return sprintf(
                'This audience has %s people; you can send to at most %s per campaign. Narrow it with the filters (an event, a city, recent buyers).',
                number_format($recipients),
                number_format($rules['max_recipients']),
            );
        }

        foreach (['campaigns_per_day' => [now()->subDay(), 'a day', 'day'], 'campaigns_per_week' => [now()->subWeek(), 'a week', 'week']] as $key => [$since, $per, $unit]) {
            if ($rules[$key] > 0 && self::campaignsSince($organizerId, $since) >= $rules[$key]) {
                $next = EmailCampaign::where('organizer_id', $organizerId)->where('kind', 'standard')
                    ->where('started_at', '>=', $since)->orderBy('started_at')
                    ->skip(max(0, self::campaignsSince($organizerId, $since) - $rules[$key]))->value('started_at');

                return sprintf(
                    'You can start %d campaign%s %s. %s',
                    $rules[$key],
                    $rules[$key] === 1 ? '' : 's',
                    $per,
                    $next ? 'The next one can start after '.Dates::display(Carbon::parse($next)->add(1, $unit), 'j M, g:ia').'.' : 'Try again later.',
                );
            }
        }

        return null;
    }

    // ---- writing ----------------------------------------------------------------------

    public static function saveGlobal(array $values): void
    {
        $current = Setting::getArray('edm_organizers', []);

        foreach (['enabled', 'access', 'premium_allowance', 'free_allowance', 'packs', 'guard', ...array_keys(self::LIMITS), ...array_keys(self::FEATURES)] as $key) {
            if (array_key_exists($key, $values)) {
                $current[$key] = $values[$key];
            }
        }

        Setting::putArray('edm_organizers', $current);
    }

    /**
     * Set one organizer's access and overrides. A null value clears that
     * override, so the global rule applies again.
     */
    public static function saveFor(int $organizerId, string $access, array $overrides): void
    {
        $rules = [];

        foreach (self::LIMITS as $key => [, $max]) {
            if (isset($overrides[$key]) && $overrides[$key] !== '' && is_numeric($overrides[$key])) {
                $rules[$key] = max(0, min($max, (int) $overrides[$key]));
            }
        }

        foreach (array_keys(self::FEATURES) as $key) {
            if (isset($overrides[$key]) && is_bool($overrides[$key])) {
                $rules[$key] = $overrides[$key];
            }
        }

        EdmAccount::for($organizerId)->forceFill([
            'access' => in_array($access, ['inherit', 'enabled', 'disabled'], true) ? $access : 'inherit',
            'rules' => $rules ?: null,
        ])->save();
    }

    // ---- helpers ------------------------------------------------------------------------

    private static function int(mixed $value, int $default, int $max): int
    {
        return is_numeric($value) ? max(0, min($max, (int) $value)) : $default;
    }

    private static function rate(mixed $value, float $default): float
    {
        return is_numeric($value) ? max(0.0, min(1.0, (float) $value)) : $default;
    }

    /** @return array<int, array{key: string, name: string, credits: int, price: float}> */
    private static function packs(mixed $saved, array $fallback): array
    {
        $rows = is_array($saved) && $saved !== [] ? $saved : $fallback;

        return array_values(array_filter(array_map(fn ($p) => is_array($p) && ! empty($p['key']) ? [
            'key' => (string) $p['key'],
            'name' => (string) ($p['name'] ?? $p['key']),
            'credits' => max(1, (int) ($p['credits'] ?? 0)),
            'price' => round(max(0, (float) ($p['price'] ?? 0)), 2),
        ] : null, $rows)));
    }
}

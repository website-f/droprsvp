<?php

namespace App\Support\Edm;

use App\Models\EmailConsent;
use App\Models\EmailSuppression;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Marketing consent: the one place that decides who may receive a campaign.
 *
 * Nothing else should write email_consents. Every path that grants or revokes
 * — checkout, registration, the settings page, an unsubscribe link, the
 * re-permission email — comes through here, so the rules cannot drift between
 * them:
 *
 *  - Addresses are normalised (trimmed, lower-cased) before they are stored or
 *    compared. "Aisyah@Gmail.com " and "aisyah@gmail.com" are one person, and a
 *    case mismatch must never let a mail through to someone who unsubscribed.
 *  - A grant is an explicit act. It may override an earlier unsubscribe — the
 *    person chose again — but nothing grants consent implicitly.
 *  - A revoke always leaves a row behind, even for an address we never had
 *    consent for. That row is what stops a later import or re-grant path from
 *    quietly putting them back.
 *  - Suppressed addresses (hard bounces, complaints) are never mailable,
 *    whatever their consent says.
 */
final class Consent
{
    public const PLATFORM = 'platform';

    /** Grant sources a person can choose for themselves. */
    public const SOURCES = ['checkout', 'register', 'settings', 'repermission', 'admin'];

    /** The scope string for a list: DropRSVP's own, or one organizer's. */
    public static function scope(?int $organizerId = null): string
    {
        return $organizerId ? "organizer:{$organizerId}" : self::PLATFORM;
    }

    public static function normalise(?string $email): ?string
    {
        $email = strtolower(trim((string) $email));

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    /** Record an explicit opt-in. Returns null when the address is unusable. */
    public static function grant(
        ?string $email,
        string $source,
        ?User $user = null,
        ?int $organizerId = null,
        ?string $ip = null,
    ): ?EmailConsent {
        $email = self::normalise($email);

        if (! $email) {
            return null;
        }

        return EmailConsent::updateOrCreate(
            ['email' => $email, 'scope' => self::scope($organizerId)],
            [
                'organizer_id' => $organizerId,
                // Keep a link we already had rather than dropping it on a guest
                // re-grant that arrives without an account.
                'user_id' => $user?->id ?? self::existingUserId($email, $organizerId),
                'status' => 'subscribed',
                'source' => $source,
                'consented_at' => now(),
                'unsubscribed_at' => null,
                'ip' => $ip,
            ],
        );
    }

    /** Record an opt-out. Always leaves a row, even if we never had consent. */
    public static function revoke(
        ?string $email,
        string $source = 'unsubscribe',
        ?int $organizerId = null,
        ?string $ip = null,
    ): ?EmailConsent {
        $email = self::normalise($email);

        if (! $email) {
            return null;
        }

        return EmailConsent::updateOrCreate(
            ['email' => $email, 'scope' => self::scope($organizerId)],
            [
                'organizer_id' => $organizerId,
                'user_id' => self::existingUserId($email, $organizerId) ?? User::where('email', $email)->value('id'),
                'status' => 'unsubscribed',
                'source' => $source,
                'unsubscribed_at' => now(),
                'ip' => $ip,
            ],
        );
    }

    /** Whether this address has opted in to this list. Ignores suppression. */
    public static function isSubscribed(?string $email, ?int $organizerId = null): bool
    {
        $email = self::normalise($email);

        return $email !== null && EmailConsent::where('email', $email)
            ->where('scope', self::scope($organizerId))
            ->where('status', 'subscribed')
            ->exists();
    }

    /**
     * Whether a campaign may actually be sent to this address right now.
     *
     * The send-time check, deliberately separate from building the audience:
     * someone can unsubscribe, or bounce from another campaign, between the
     * moment a campaign is queued and the moment their copy goes out.
     */
    public static function mayEmail(?string $email, ?int $organizerId = null): bool
    {
        $email = self::normalise($email);

        return $email !== null
            && self::isSubscribed($email, $organizerId)
            && ! self::isSuppressed($email);
    }

    /**
     * Whether the one-off re-permission email may still go to this address.
     *
     * Only to someone who has made NO choice yet. If they opted in since the
     * list was built, there is nothing to ask; if they opted out, asking would
     * ignore the answer they already gave.
     */
    public static function mayAskPermission(?string $email): bool
    {
        $email = self::normalise($email);

        return $email !== null
            && ! EmailConsent::where('email', $email)->where('scope', self::PLATFORM)->exists()
            && ! self::isSuppressed($email);
    }

    public static function isSuppressed(?string $email): bool
    {
        $email = self::normalise($email);

        return $email !== null && EmailSuppression::where('email', $email)->exists();
    }

    /** Never mail this address again, from any list. */
    public static function suppress(?string $email, string $reason, ?string $detail = null): void
    {
        $email = self::normalise($email);

        if (! $email) {
            return;
        }

        EmailSuppression::updateOrCreate(
            ['email' => $email],
            ['reason' => $reason, 'detail' => $detail ? mb_substr($detail, 0, 255) : null],
        );
    }

    /**
     * Subscription status for many addresses in one query.
     *
     * For lists — the abandoned-checkout table, an audience preview — where a
     * per-row lookup would be one query per person.
     *
     * @param  iterable<string|null>  $emails
     * @return array<string,bool> normalised email => subscribed
     */
    public static function subscribedMap(iterable $emails, ?int $organizerId = null): array
    {
        $normalised = Collection::make($emails)
            ->map(fn ($e) => self::normalise($e))
            ->filter()
            ->unique()
            ->values();

        if ($normalised->isEmpty()) {
            return [];
        }

        $subscribed = EmailConsent::whereIn('email', $normalised->all())
            ->where('scope', self::scope($organizerId))
            ->where('status', 'subscribed')
            ->pluck('email')
            ->flip();

        return $normalised->mapWithKeys(fn ($e) => [$e => $subscribed->has($e)])->all();
    }

    /**
     * Follow a user to their new address.
     *
     * Consent is keyed on the address mail is sent to. When a signed-in user
     * changes their email, the decision they made belongs to them, not to the
     * string they used to have — an opt-out in particular must not be left
     * behind on the old address while the new one starts from nothing.
     */
    public static function moveToNewAddress(User $user, ?string $oldEmail, ?string $newEmail): void
    {
        $old = self::normalise($oldEmail);
        $new = self::normalise($newEmail);

        if (! $old || ! $new || $old === $new) {
            return;
        }

        EmailConsent::where('email', $old)->get()->each(function (EmailConsent $row) use ($new, $user) {
            // A row for the new address in the same scope already exists — they
            // made a separate decision there. Keep theirs, drop the old one.
            $clash = EmailConsent::where('email', $new)->where('scope', $row->scope)->exists();

            if ($clash) {
                $row->delete();

                return;
            }

            $row->forceFill(['email' => $new, 'user_id' => $user->id])->save();
        });
    }

    private static function existingUserId(string $email, ?int $organizerId): ?int
    {
        return EmailConsent::where('email', $email)
            ->where('scope', self::scope($organizerId))
            ->value('user_id');
    }
}

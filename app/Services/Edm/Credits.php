<?php

namespace App\Services\Edm;

use App\Models\EdmAccount;
use App\Models\EdmCreditEntry;
use App\Models\EmailCampaign;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * What an organizer may send: one email, one credit.
 *
 * Two pools, spent in this order:
 *
 *  allowance  free each calendar month for premium organizers (or an
 *             override a superadmin set). Unused allowance does not roll
 *             over — it is a monthly gift, not a balance.
 *  credits    bought in packs through CHIP, or granted by a superadmin.
 *             Never expire.
 *
 * A campaign RESERVES its whole recipient count when it starts, so two
 * campaigns started together cannot both spend the same credits, and a
 * campaign can never be part-way through when the money runs out. When it
 * finishes or is cancelled, everything that was not actually sent (skipped,
 * failed, cancelled) is refunded — purchased credits first, since those are
 * what the organizer paid for.
 *
 * Every change is a signed row in edm_credit_ledger, and every figure here is
 * a SUM over it: there is no stored balance to drift from the history.
 */
final class Credits
{
    public static function period(): string
    {
        return now()->format('Y-m');
    }

    /** This month's free allowance for an organizer. */
    public static function allowance(User $organizer): int
    {
        $override = EdmAccount::where('organizer_id', $organizer->id)->value('monthly_allowance');

        if ($override !== null) {
            return (int) $override;
        }

        return (int) ($organizer->isPremium()
            ? config('edm.organizers.premium_allowance', 2000)
            : config('edm.organizers.free_allowance', 0));
    }

    public static function allowanceUsed(int $organizerId): int
    {
        return max(0, -(int) EdmCreditEntry::where('organizer_id', $organizerId)
            ->where('pool', 'allowance')->where('period', self::period())->sum('delta'));
    }

    public static function allowanceLeft(User $organizer): int
    {
        return max(0, self::allowance($organizer) - self::allowanceUsed($organizer->id));
    }

    public static function balance(int $organizerId): int
    {
        return (int) EdmCreditEntry::where('organizer_id', $organizerId)->where('pool', 'credits')->sum('delta');
    }

    public static function available(User $organizer): int
    {
        return self::allowanceLeft($organizer) + max(0, self::balance($organizer->id));
    }

    /** @return array{allowance: int, allowance_used: int, allowance_left: int, credits: int, available: int, premium: bool, period: string} */
    public static function summary(User $organizer): array
    {
        return [
            'allowance' => self::allowance($organizer),
            'allowance_used' => self::allowanceUsed($organizer->id),
            'allowance_left' => self::allowanceLeft($organizer),
            'credits' => self::balance($organizer->id),
            'available' => self::available($organizer),
            'premium' => $organizer->isPremium(),
            'period' => now()->format('F Y'),
        ];
    }

    /**
     * Take a campaign's recipient count from the organizer's quota.
     *
     * Serialised per organizer (their account row is locked), so concurrent
     * starts cannot overspend.
     *
     * @throws RuntimeException when there is not enough left
     */
    public static function reserve(EmailCampaign $campaign, int $count): void
    {
        if (! $campaign->organizer_id || $count <= 0) {
            return;
        }

        // Exists before the transaction, so there is always a row to lock.
        EdmAccount::for($campaign->organizer_id);

        DB::transaction(function () use ($campaign, $count) {
            EdmAccount::where('organizer_id', $campaign->organizer_id)->lockForUpdate()->first();

            $organizer = User::findOrFail($campaign->organizer_id);
            $allowanceLeft = self::allowanceLeft($organizer);
            $credits = max(0, self::balance($organizer->id));

            if ($allowanceLeft + $credits < $count) {
                throw new RuntimeException(sprintf(
                    'Not enough email credits: this campaign goes to %s people and you have %s left. Buy a credit pack, or narrow the audience.',
                    number_format($count),
                    number_format($allowanceLeft + $credits),
                ));
            }

            $fromAllowance = min($allowanceLeft, $count);
            $fromCredits = $count - $fromAllowance;

            if ($fromAllowance > 0) {
                self::entry($campaign->organizer_id, 'allowance', -$fromAllowance, 'reserve', $campaign->id, note: "Reserved for “{$campaign->name}”");
            }
            if ($fromCredits > 0) {
                self::entry($campaign->organizer_id, 'credits', -$fromCredits, 'reserve', $campaign->id, note: "Reserved for “{$campaign->name}”");
            }

            $campaign->forceFill(['allowance_reserved' => $fromAllowance, 'credits_reserved' => $fromCredits])->save();
        });
    }

    /**
     * Hand back what a finished or cancelled campaign did not send. Runs once
     * per campaign. Credits first; allowance only within the month it came from.
     */
    public static function settle(EmailCampaign $campaign): void
    {
        if (! $campaign->organizer_id || $campaign->credits_settled_at !== null) {
            return;
        }

        DB::transaction(function () use ($campaign) {
            $locked = EmailCampaign::whereKey($campaign->id)->lockForUpdate()->first();

            if (! $locked || $locked->credits_settled_at !== null) {
                return;
            }

            $reserved = $locked->allowance_reserved + $locked->credits_reserved;
            // Sent mail is spent whatever happened next (a bounce still used the slot).
            $unused = max(0, $reserved - (int) $locked->sent_count);

            $toCredits = min($unused, $locked->credits_reserved);
            $toAllowance = $unused - $toCredits;

            if ($toCredits > 0) {
                self::entry($locked->organizer_id, 'credits', $toCredits, 'refund', $locked->id, note: "Unsent from “{$locked->name}”");
            }

            // Allowance is per month: refunding into a month that has passed
            // would revive an expired gift, so only the current month's is returned.
            $reservedIn = EdmCreditEntry::where('campaign_id', $locked->id)->where('pool', 'allowance')->where('reason', 'reserve')->value('period');
            if ($toAllowance > 0 && $reservedIn === self::period()) {
                self::entry($locked->organizer_id, 'allowance', $toAllowance, 'refund', $locked->id, note: "Unsent from “{$locked->name}”");
            }

            $locked->forceFill(['credits_settled_at' => now()])->save();
        });
    }

    /**
     * Spend credits for automated sends, which go out one person at a time
     * rather than as a campaign. Returns false (spending nothing) when the
     * organizer has run out — the sequence then skips that email.
     */
    public static function spend(int $organizerId, int $count, int $campaignId, string $note): bool
    {
        EdmAccount::for($organizerId);

        return DB::transaction(function () use ($organizerId, $count, $campaignId, $note) {
            EdmAccount::where('organizer_id', $organizerId)->lockForUpdate()->first();
            $organizer = User::findOrFail($organizerId);

            $allowanceLeft = self::allowanceLeft($organizer);
            $credits = max(0, self::balance($organizerId));

            if ($allowanceLeft + $credits < $count) {
                return false;
            }

            $fromAllowance = min($allowanceLeft, $count);
            if ($fromAllowance > 0) {
                self::entry($organizerId, 'allowance', -$fromAllowance, 'automation', $campaignId, note: $note);
            }
            if ($count - $fromAllowance > 0) {
                self::entry($organizerId, 'credits', -($count - $fromAllowance), 'automation', $campaignId, note: $note);
            }

            return true;
        });
    }

    /** Add (or, negative, remove) purchased credits — a purchase or an admin adjustment. */
    public static function grant(int $organizerId, int $credits, string $reason = 'adjust', ?string $note = null, ?int $purchaseId = null, ?int $by = null): void
    {
        self::entry($organizerId, 'credits', $credits, $reason, null, $purchaseId, $note, $by);
    }

    private static function entry(int $organizerId, string $pool, int $delta, string $reason, ?int $campaignId = null, ?int $purchaseId = null, ?string $note = null, ?int $by = null): void
    {
        EdmCreditEntry::create([
            'organizer_id' => $organizerId,
            'pool' => $pool,
            'period' => $pool === 'allowance' ? self::period() : null,
            'delta' => $delta,
            'reason' => $reason,
            'campaign_id' => $campaignId,
            'purchase_id' => $purchaseId,
            'note' => $note ? mb_substr($note, 0, 255) : null,
            'created_by' => $by,
        ]);
    }
}

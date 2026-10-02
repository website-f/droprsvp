<?php

namespace App\Services\Edm;

use App\Models\EdmAccount;
use App\Models\EmailBounce;
use App\Models\EmailCampaign;
use App\Models\EmailSend;
use App\Support\PlatformAlert;

/**
 * Abuse guardrails for organizer email.
 *
 * Every organizer sends from the same server, so one organizer mailing a
 * bought or stale list damages delivery for all of them — and for ticket
 * receipts. Over the last 30 days, once enough has gone out to judge, an
 * organizer whose bounce, unsubscribe or complaint rate passes the limit is
 * SUSPENDED: their sending campaigns pause, they cannot start new ones, and a
 * superadmin reviews them in EDM → Organizers.
 */
final class OrganizerGuard
{
    /**
     * Their rates over the last 30 days.
     *
     * @return array{sent: int, bounced: int, unsubscribed: int, complaints: int, bounce_rate: float, unsubscribe_rate: float, complaint_rate: float}
     */
    public static function rates(int $organizerId): array
    {
        $sends = EmailSend::query()
            ->whereHas('campaign', fn ($q) => $q->where('organizer_id', $organizerId))
            ->where('sent_at', '>=', now()->subDays(30));

        $sent = (clone $sends)->count();
        $bounced = (clone $sends)->where('status', 'bounced')->count();
        $unsubscribed = (clone $sends)->whereNotNull('unsubscribed_at')->count();
        $complaints = EmailBounce::where('type', 'complaint')
            ->where('created_at', '>=', now()->subDays(30))
            ->whereHas('campaign', fn ($q) => $q->where('organizer_id', $organizerId))
            ->count();

        $rate = fn (int $n) => $sent > 0 ? round($n / $sent, 4) : 0.0;

        return [
            'sent' => $sent,
            'bounced' => $bounced,
            'unsubscribed' => $unsubscribed,
            'complaints' => $complaints,
            'bounce_rate' => $rate($bounced),
            'unsubscribe_rate' => $rate($unsubscribed),
            'complaint_rate' => $rate($complaints),
        ];
    }

    /** Why this organizer should be suspended, or null if they are fine. */
    public static function breach(int $organizerId): ?string
    {
        $g = config('edm.organizers.guard');
        $r = self::rates($organizerId);

        if ($r['sent'] < (int) ($g['min_sent'] ?? 200)) {
            return null;
        }

        return match (true) {
            $r['bounce_rate'] > (float) $g['bounce_rate'] => sprintf('Bounce rate %.1f%% over the last 30 days (limit %.0f%%). The list likely has addresses that do not exist.', 100 * $r['bounce_rate'], 100 * $g['bounce_rate']),
            $r['complaint_rate'] > (float) $g['complaint_rate'] => sprintf('Spam complaint rate %.2f%% over the last 30 days (limit %.1f%%).', 100 * $r['complaint_rate'], 100 * $g['complaint_rate']),
            $r['unsubscribe_rate'] > (float) $g['unsubscribe_rate'] => sprintf('Unsubscribe rate %.1f%% over the last 30 days (limit %.0f%%). Readers did not expect this mail.', 100 * $r['unsubscribe_rate'], 100 * $g['unsubscribe_rate']),
            default => null,
        };
    }

    /** Check every organizer who has sent recently; suspend those past the limits. */
    public static function sweep(): int
    {
        $suspended = 0;

        $organizers = EmailCampaign::query()
            ->whereNotNull('organizer_id')
            ->whereIn('status', ['sending', 'paused', 'sent'])
            ->where('started_at', '>=', now()->subDays(30))
            ->distinct()
            ->pluck('organizer_id');

        foreach ($organizers as $id) {
            $account = EdmAccount::for((int) $id);

            // A superadmin reviewed and reinstated them in the last week: give
            // the clean-up time to show before judging again on old numbers.
            if ($account->isSuspended() || ($account->reviewed_at && $account->reviewed_at->gt(now()->subDays(7)))) {
                continue;
            }

            if ($reason = self::breach((int) $id)) {
                self::suspend($account, $reason);
                $suspended++;
            }
        }

        return $suspended;
    }

    public static function suspend(EdmAccount $account, string $reason, ?int $by = null): void
    {
        $account->forceFill([
            'status' => 'suspended',
            'suspended_reason' => mb_substr($reason, 0, 255),
            'suspended_at' => now(),
            'reviewed_by' => $by,
        ])->save();

        EmailCampaign::where('organizer_id', $account->organizer_id)
            ->where('status', 'sending')
            ->update(['status' => 'paused', 'paused_reason' => 'Sending suspended: '.mb_substr($reason, 0, 200)]);

        if ($by === null) {
            $name = $account->organizer?->organizerProfile?->business_name ?: $account->organizer?->name;

            PlatformAlert::raise(
                type: 'edm',
                title: 'Organizer email suspended automatically',
                body: "{$name}: {$reason}",
                url: '/admin/edm/organizers',
                details: ['Organizer' => (string) $name, 'Reason' => $reason],
                level: 'warning',
            );
        }
    }

    public static function reinstate(EdmAccount $account, int $by): void
    {
        $account->forceFill([
            'status' => 'active',
            'suspended_reason' => null,
            'suspended_at' => null,
            'reviewed_by' => $by,
            'reviewed_at' => now(),
        ])->save();
    }
}

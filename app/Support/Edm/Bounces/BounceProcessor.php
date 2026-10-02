<?php

namespace App\Support\Edm\Bounces;

use App\Models\EmailBounce;
use App\Models\EmailSend;
use App\Support\Edm\Consent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Acts on a returned email: records each bounce, marks the send, counts it
 * against its campaign, and suppresses the address when the bounce says it
 * will never work.
 *
 *  hard      suppressed at once — mailing a dead address again is the fastest
 *            way onto a blocklist.
 *  soft      recorded; suppressed after SOFT_LIMIT soft bounces in 30 days
 *            (a mailbox that has been "full" for a month is abandoned).
 *  blocked   recorded and counted, never suppressed: the receiving server
 *            refused us, not the reader.
 *  complaint suppressed and unsubscribed from the list it came from.
 *
 * Idempotent: the same bounce read twice (a mailbox re-scan, a pipe retry) is
 * recognised by its Message-ID and does nothing the second time.
 */
final class BounceProcessor
{
    public const SOFT_LIMIT = 3;

    /**
     * @return array{bounces: int, suppressed: int, matched: int}
     */
    public static function handle(string $raw): array
    {
        $ignore = array_filter([
            config('edm.from.address'),
            config('mail.from.address'),
            config('edm.bounces.username'),
        ]);

        $summary = ['bounces' => 0, 'suppressed' => 0, 'matched' => 0];

        foreach (BounceParser::parse($raw, $ignore) as $b) {
            $send = self::findSend($b);

            try {
                $bounce = EmailBounce::create([
                    'email' => $b['email'],
                    'send_id' => $send?->id,
                    'campaign_id' => $send?->campaign_id ?? $b['campaign_id'],
                    'type' => $b['type'],
                    'status_code' => $b['status'],
                    'diagnostic' => $b['diagnostic'],
                    'message_id' => $b['message_id'],
                ]);
            } catch (QueryException) {
                continue; // this bounce was already processed
            }

            $summary['bounces']++;

            if ($send) {
                $summary['matched']++;
                self::markSend($send, $bounce);
            }

            if (self::suppressIfDue($bounce, $send)) {
                $summary['suppressed']++;
            }
        }

        return $summary;
    }

    /** @param array{email: string, token: ?string, campaign_id: ?int} $b */
    private static function findSend(array $b): ?EmailSend
    {
        if ($b['token']) {
            $send = EmailSend::where('token', $b['token'])->first();

            // The token names one recipient; a multi-recipient bounce quoting it
            // must not pin every address onto that one send.
            if ($send && $send->email === $b['email']) {
                return $send;
            }
        }

        return EmailSend::query()
            ->where('email', $b['email'])
            ->whereNotNull('sent_at')
            ->when($b['campaign_id'], fn ($q) => $q->where('campaign_id', $b['campaign_id']))
            ->where('sent_at', '>=', now()->subDays(14))
            ->latest('sent_at')
            ->first();
    }

    private static function markSend(EmailSend $send, EmailBounce $bounce): void
    {
        if ($bounce->type === 'complaint') {
            if ($send->unsubscribed_at === null) {
                $send->forceFill(['unsubscribed_at' => now()])->save();
                $send->campaign()->increment('unsubscribed_count');
            }

            return;
        }

        DB::transaction(function () use ($send, $bounce) {
            $locked = EmailSend::whereKey($send->id)->lockForUpdate()->first();

            // One bounce per send in the campaign's count, however many
            // notices arrive about it (a soft bounce then a final failure).
            if ($locked && $locked->status !== 'bounced') {
                $locked->forceFill([
                    'status' => 'bounced',
                    'error' => mb_substr(trim(ucfirst($bounce->type).' bounce '.($bounce->status_code ?? '').': '.($bounce->diagnostic ?? '')), 0, 255),
                ])->save();
                $locked->campaign()->increment('bounced_count');
            }
        });
    }

    private static function suppressIfDue(EmailBounce $bounce, ?EmailSend $send): bool
    {
        $email = $bounce->email;

        switch ($bounce->type) {
            case 'hard':
                Consent::suppress($email, 'bounce', trim(($bounce->status_code ?? '').' '.($bounce->diagnostic ?? '')) ?: 'Hard bounce');

                return true;

            case 'complaint':
                Consent::suppress($email, 'complaint', $bounce->diagnostic);
                Consent::revoke($email, 'complaint', $send?->campaign?->organizer_id);

                return true;

            case 'soft':
                $recent = EmailBounce::where('email', $email)->where('type', 'soft')
                    ->where('created_at', '>=', now()->subDays(30))->count();

                if ($recent >= self::SOFT_LIMIT) {
                    Consent::suppress($email, 'bounce', "{$recent} temporary failures in 30 days. Last: ".($bounce->diagnostic ?? $bounce->status_code ?? 'unknown'));

                    return true;
                }

                return false;

            default:
                return false; // 'blocked' is about us, never the reader
        }
    }
}

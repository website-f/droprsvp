<?php

namespace App\Services\Edm;

use App\Mail\CampaignMail;
use App\Models\EmailCampaign;
use App\Models\EmailSend;
use App\Support\Edm\Audience;
use App\Support\Edm\Consent;
use App\Support\Edm\Personalizer;
use App\Support\Edm\Renderer;
use App\Support\Edm\Settings;
use App\Support\Edm\Throttle;
use App\Support\PlatformAlert;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A campaign's life: draft -> (scheduled) -> sending -> sent, with paused and
 * cancelled on the side.
 *
 * start() freezes the content and writes one email_sends row per recipient.
 * dispatch() — run every minute by `edm:send` — takes the next batch of those
 * rows, as many as the Throttle allows, and sends them one at a time.
 *
 * Built for shared hosting: no queue worker, no daemon, no Redis. The send
 * table is the queue, the scheduler cron is the worker, and a run that is
 * killed half-way (the host's CPU limit) leaves every unsent row exactly where
 * it was for the next minute to pick up.
 */
class CampaignSender
{
    /** A send is retried this many times before it is given up as failed. */
    private const MAX_ATTEMPTS = 3;

    /** Start sending now: freeze the content, build the recipient list. */
    public function start(EmailCampaign $campaign): EmailCampaign
    {
        if (! in_array($campaign->status, ['draft', 'scheduled'], true)) {
            throw new RuntimeException("Campaign is {$campaign->status}, not ready to send.");
        }

        $this->assertSendable($campaign);

        DB::transaction(function () use ($campaign) {
            $this->freeze($campaign);

            $count = $this->enqueue($campaign);

            $campaign->forceFill([
                'status' => $count > 0 ? 'sending' : 'sent',
                'recipients_count' => $count,
                'started_at' => now(),
                'finished_at' => $count > 0 ? null : now(),
                'paused_reason' => null,
            ])->save();
        });

        return $campaign->refresh();
    }

    /** Hold a campaign for a later start. */
    public function schedule(EmailCampaign $campaign, \DateTimeInterface $at): EmailCampaign
    {
        if (! $campaign->isEditable()) {
            throw new RuntimeException("Campaign is {$campaign->status} and can no longer be scheduled.");
        }

        $this->assertSendable($campaign);

        $campaign->forceFill(['status' => 'scheduled', 'scheduled_at' => $at])->save();

        return $campaign;
    }

    public function pause(EmailCampaign $campaign, string $reason = 'Paused by an admin.'): void
    {
        if ($campaign->status === 'sending') {
            $campaign->forceFill(['status' => 'paused', 'paused_reason' => mb_substr($reason, 0, 255)])->save();
        }
    }

    public function resume(EmailCampaign $campaign): void
    {
        if ($campaign->status === 'paused') {
            $campaign->forceFill(['status' => 'sending', 'paused_reason' => null])->save();
        }
    }

    /** Stop for good. Anything not yet sent is marked skipped. */
    public function cancel(EmailCampaign $campaign): void
    {
        if (in_array($campaign->status, ['sent', 'cancelled'], true)) {
            return;
        }

        DB::transaction(function () use ($campaign) {
            $campaign->sends()->where('status', 'queued')->update(['status' => 'skipped', 'error' => 'Campaign cancelled']);
            $campaign->forceFill(['status' => 'cancelled', 'finished_at' => now()])->save();
        });
    }

    /**
     * One run of the dispatcher. Returns how many emails went out.
     *
     * Starts any scheduled campaign whose time has come, then sends up to the
     * throttle's budget, oldest queued first, across every sending campaign.
     */
    public function dispatch(): int
    {
        EmailCampaign::where('status', 'scheduled')
            ->where('scheduled_at', '<=', now())
            ->get()
            ->each(function (EmailCampaign $c) {
                try {
                    $this->start($c);
                } catch (\Throwable $e) {
                    // A scheduled campaign that cannot start (its content was
                    // emptied, say) must not block the others behind it.
                    $c->forceFill(['status' => 'paused', 'paused_reason' => 'Could not start: '.$e->getMessage()])->save();
                    report($e);
                }
            });

        // Before sending as well as after: a campaign already over the bounce
        // threshold must not send one more batch on its way to stopping.
        $this->checkBounceRates();

        $budget = Throttle::budget();

        if ($budget <= 0) {
            return 0;
        }

        $sends = EmailSend::query()
            ->where('status', 'queued')
            ->whereHas('campaign', fn ($q) => $q->where('status', 'sending'))
            ->with('campaign')
            ->orderBy('id')
            ->limit($budget)
            ->get();

        $sent = 0;
        $failuresInARow = 0;
        $threshold = (int) config('edm.auto_pause.consecutive_failures', 10);

        foreach ($sends as $send) {
            // A pause can land mid-run (an admin, or the bounce check below).
            if ($send->campaign->fresh()->status !== 'sending') {
                continue;
            }

            $result = $this->deliver($send);

            if ($result === 'sent') {
                $sent++;
                $failuresInARow = 0;
            } elseif ($result === 'failed') {
                $failuresInARow++;

                // The server refusing mail over and over is not a property of
                // these recipients — it is a rate limit, a blocked account, or a
                // broken login. Hammering it makes every one of those worse.
                if ($failuresInARow >= $threshold) {
                    $this->autoPause($send->campaign, "Stopped after {$failuresInARow} sending failures in a row. Last error: ".($send->error ?? 'unknown'));

                    break;
                }
            }
        }

        $this->finishCompleted();
        $this->checkBounceRates();

        return $sent;
    }

    /** @return 'sent'|'failed'|'skipped' */
    private function deliver(EmailSend $send): string
    {
        $campaign = $send->campaign;

        // Checked again now, not only when the list was built: they may have
        // unsubscribed, or bounced from another campaign, in the meantime.
        if (! Consent::mayEmail($send->email, $campaign->organizer_id)) {
            $send->forceFill(['status' => 'skipped', 'error' => 'No longer subscribed or suppressed'])->save();

            return 'skipped';
        }

        $message = Personalizer::forRecipient($campaign, $send);

        try {
            Mail::mailer(config('edm.mailer', 'edm'))
                ->to($send->email, $send->name ?: null)
                ->send(new CampaignMail(
                    subjectLine: $message['subject'],
                    htmlBody: $message['html'],
                    textBody: $message['text'],
                    unsubscribeUrl: route('edm.unsubscribe', ['token' => $send->token]),
                    fromAddress: (string) config('edm.from.address'),
                    fromName: $campaign->from_name ?: (string) Settings::get('from_name'),
                    replyToAddress: $campaign->reply_to ?: (Settings::get('reply_to') ?: null),
                    campaignTag: 'c'.$campaign->id,
                ));
        } catch (\Throwable $e) {
            $attempts = $send->attempts + 1;
            $final = $attempts >= self::MAX_ATTEMPTS;

            $send->forceFill([
                'attempts' => $attempts,
                // Left queued to retry next minute until the attempts run out.
                'status' => $final ? 'failed' : 'queued',
                'error' => mb_substr($e->getMessage(), 0, 255),
            ])->save();

            if ($final) {
                $campaign->increment('failed_count');
            }

            report($e);

            return 'failed';
        }

        $send->forceFill([
            'status' => 'sent',
            'sent_at' => now(),
            'attempts' => $send->attempts + 1,
            'error' => null,
        ])->save();

        $campaign->increment('sent_count');

        return 'sent';
    }

    /** Freeze the content: render the design once, register its links. */
    private function freeze(EmailCampaign $campaign): void
    {
        $rendered = Renderer::render((array) $campaign->design, $this->context($campaign));

        $campaign->forceFill([
            'html' => Personalizer::prepareLinks($campaign, $rendered['html']),
            'text' => $rendered['text'],
        ])->save();
    }

    /** One send row per recipient, inserted in chunks. Returns the count. */
    private function enqueue(EmailCampaign $campaign): int
    {
        $now = now();

        Audience::query($campaign->audience, $campaign->organizer_id)
            ->orderBy('email_consents.id')
            ->chunk(500, function ($rows) use ($campaign, $now) {
                $insert = $rows->map(fn ($r) => [
                    'campaign_id' => $campaign->id,
                    'user_id' => $r->user_id,
                    'email' => $r->email,
                    'name' => $r->name,
                    'token' => Str::random(40),
                    'status' => 'queued',
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                // insertOrIgnore: the (campaign, email) unique index is the
                // final word on "one copy per person".
                DB::table('email_sends')->insertOrIgnore($insert);
            });

        return $campaign->sends()->count();
    }

    private function context(EmailCampaign $campaign): array
    {
        $sender = $campaign->from_name ?: (string) Settings::get('from_name');

        return [
            'subject' => $campaign->subject,
            'preheader' => $campaign->preheader,
            'sender' => $sender,
            'address' => (string) Settings::get('postal_address'),
            'reason' => $campaign->organizer_id
                ? "You are receiving this because you opted in to emails from {$sender} on DropRSVP."
                : 'You are receiving this because you opted in to emails from DropRSVP.',
        ];
    }

    private function assertSendable(EmailCampaign $campaign): void
    {
        if (trim((string) $campaign->subject) === '') {
            throw new RuntimeException('Add a subject line before sending.');
        }

        if (empty($campaign->design['content'])) {
            throw new RuntimeException('The email has no content yet.');
        }
    }

    private function finishCompleted(): void
    {
        EmailCampaign::where('status', 'sending')
            ->whereDoesntHave('sends', fn ($q) => $q->where('status', 'queued'))
            ->get()
            ->each(fn (EmailCampaign $c) => $c->forceFill(['status' => 'sent', 'finished_at' => now()])->save());
    }

    /**
     * Pause a campaign whose bounces suggest a bad list.
     *
     * A high bounce rate is the earliest sign of mailing addresses that do not
     * exist — and mailbox providers read it as a spammer's signature. Better to
     * stop at 5% and look than to find out from a blocklist.
     */
    private function checkBounceRates(): void
    {
        $min = (int) config('edm.auto_pause.min_sent', 50);
        $rate = (float) config('edm.auto_pause.bounce_rate', 0.05);

        EmailCampaign::where('status', 'sending')
            ->where('sent_count', '>=', $min)
            ->get()
            ->filter(fn (EmailCampaign $c) => $c->bounced_count / max(1, $c->sent_count) > $rate)
            ->each(fn (EmailCampaign $c) => $this->autoPause(
                $c,
                sprintf('Bounce rate %.1f%% is above %.0f%%. Check the list before resuming.', 100 * $c->bounced_count / max(1, $c->sent_count), 100 * $rate),
            ));
    }

    private function autoPause(EmailCampaign $campaign, string $reason): void
    {
        $this->pause($campaign, $reason);

        PlatformAlert::raise(
            type: 'edm',
            title: 'Email campaign paused automatically',
            body: "\"{$campaign->name}\": {$reason}",
            url: '/admin/edm/campaigns/'.$campaign->id,
            details: ['Campaign' => $campaign->name, 'Reason' => $reason],
            level: 'warning',
        );
    }
}

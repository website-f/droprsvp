<?php

namespace App\Services\Edm;

use App\Mail\CampaignMail;
use App\Models\EdmAccount;
use App\Models\EmailCampaign;
use App\Models\EmailSend;
use App\Models\User;
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
        $this->assertOrganizerMaySend($campaign);

        DB::transaction(function () use ($campaign) {
            $this->freeze($campaign);

            $count = $this->enqueue($campaign);

            // An organizer's campaign takes its whole recipient count from
            // their quota up front; not enough, and the whole start rolls back.
            Credits::reserve($campaign, $count);

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
        $this->assertOrganizerMaySend($campaign);

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

        Credits::settle($campaign->fresh());
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

        // Organizer rates move as their mail goes out; judge them while it does.
        if ($sends->contains(fn (EmailSend $s) => $s->campaign->organizer_id !== null)) {
            OrganizerGuard::sweep();
        }

        return $sent;
    }

    /** @return 'sent'|'failed'|'skipped' */
    private function deliver(EmailSend $send): string
    {
        $campaign = $send->campaign;

        // Checked again now, not only when the list was built: they may have
        // unsubscribed, or bounced from another campaign, in the meantime.
        $allowed = match ($campaign->kind) {
            'repermission' => Consent::mayAskPermission($send->email),
            // Automated emails go to ticket holders without marketing consent
            // (they are about something the person did), unless the step
            // promotes something — then only to subscribers.
            'automation' => Consent::mayEmailAutomation($send->email, $campaign->organizer_id, (bool) ($campaign->audience['marketing'] ?? false)),
            default => Consent::mayEmail($send->email, $campaign->organizer_id),
        };

        if (! $allowed) {
            $send->forceFill(['status' => 'skipped', 'error' => 'No longer subscribed or suppressed'])->save();

            return 'skipped';
        }

        $message = Personalizer::forRecipient($campaign, $send);

        // An organizer's own verified domain: From that address, and signed
        // with that domain's DKIM key. Anything less (not verified, records
        // removed since) falls back to the platform address — never unsigned.
        $domain = $campaign->sending_domain_id ? $campaign->sendingDomain : null;
        $ownDomain = $domain && $domain->isVerified() && $campaign->from_address
            && str_ends_with(strtolower($campaign->from_address), '@'.$domain->domain);

        $mailer = Mail::mailer(config('edm.mailer', 'edm'));
        $restore = null;

        if ($ownDomain && method_exists($mailer, 'getSymfonyTransport')) {
            $restore = $mailer->getSymfonyTransport();
            $mailer->setSymfonyTransport(new DkimSigningTransport($restore, SendingDomains::signer($domain)));
        }

        try {
            $mailer
                ->to($send->email, $send->name ?: null)
                ->send(new CampaignMail(
                    subjectLine: $message['subject'],
                    htmlBody: $message['html'],
                    textBody: $message['text'],
                    unsubscribeUrl: route('edm.unsubscribe', ['token' => $send->token]),
                    fromAddress: $ownDomain ? (string) $campaign->from_address : (string) config('edm.from.address'),
                    fromName: self::senderName($campaign),
                    replyToAddress: $campaign->reply_to ?: (Settings::get('reply_to') ?: null),
                    campaignTag: 'c'.$campaign->id,
                    sendToken: $send->token,
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
        } finally {
            if ($restore) {
                $mailer->setSymfonyTransport($restore);
            }
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
        self::freezeContent($campaign);
    }

    /** Public for automation steps, which freeze whenever they are edited or switched on. */
    public static function freezeContent(EmailCampaign $campaign): void
    {
        $rendered = Renderer::render((array) $campaign->design, self::renderContext($campaign));

        $campaign->forceFill([
            'html' => Personalizer::prepareLinks($campaign, $rendered['html']),
            'text' => $rendered['text'],
        ])->save();
    }

    /** One send row per recipient, inserted in chunks. Returns the count. */
    private function enqueue(EmailCampaign $campaign): int
    {
        $now = now();

        $source = $campaign->kind === 'repermission'
            // People who have never been asked, excluding this campaign's own
            // rows so the list does not shift under the chunked insert.
            ? Audience::repermission($campaign->id)->orderBy('people.email')
            : Audience::query($campaign->audience, $campaign->organizer_id)->orderBy('email_consents.id');

        $source->chunk(500, function ($rows) use ($campaign, $now) {
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

    /**
     * What the renderer needs to know about who is sending and why.
     *
     * Public and static so the admin preview and test send use the exact same
     * footer wording as real sends.
     */
    public static function renderContext(EmailCampaign $campaign): array
    {
        $sender = self::senderName($campaign);
        $address = (string) Settings::get('postal_address');

        // An organizer's mail carries THEIR business address — the law asks for
        // the sender's, and the reader needs to know who is writing.
        if ($campaign->organizer_id) {
            $profile = User::find($campaign->organizer_id)?->organizerProfile;
            $address = trim((string) $profile?->business_address) ?: $address;
        }

        return [
            'subject' => $campaign->subject,
            'preheader' => $campaign->preheader,
            'sender' => $sender,
            'address' => $address,
            'reason' => match (true) {
                // Not "you opted in": they have not, which is the whole point.
                $campaign->kind === 'repermission' => 'You are receiving this one-off email because you have an account or bought a ticket on DropRSVP. We will not send marketing emails unless you say yes.',
                // An automated email about something the reader did (a booking,
                // a checkout) — unless the step promotes, which needs opt-in.
                $campaign->kind === 'automation' && empty($campaign->audience['marketing']) => "This is an automatic email from {$sender} about your booking on DropRSVP. Unsubscribe to stop these emails.",
                (bool) $campaign->organizer_id => "You are receiving this because you opted in to emails from {$sender} on DropRSVP.",
                default => 'You are receiving this because you opted in to emails from DropRSVP.',
            },
        ];
    }

    /** The From name: the campaign's own, else the organizer's business name, else the platform's. */
    public static function senderName(EmailCampaign $campaign): string
    {
        if ($campaign->from_name) {
            return $campaign->from_name;
        }

        if ($campaign->organizer_id && ($organizer = User::find($campaign->organizer_id))) {
            return (string) ($organizer->organizerProfile?->business_name ?: $organizer->name);
        }

        return (string) Settings::get('from_name');
    }

    /** An organizer suspended by the guardrails (or a superadmin) cannot send. */
    private function assertOrganizerMaySend(EmailCampaign $campaign): void
    {
        if (! $campaign->organizer_id) {
            return;
        }

        $account = EdmAccount::where('organizer_id', $campaign->organizer_id)->first();

        if ($account?->isSuspended()) {
            throw new RuntimeException('Email sending is suspended for this account: '.($account->suspended_reason ?: 'under review').' A DropRSVP admin will review it.');
        }
    }

    private function assertSendable(EmailCampaign $campaign): void
    {
        if (trim((string) $campaign->subject) === '') {
            throw new RuntimeException('Add a subject line before sending.');
        }

        if (empty($campaign->design['content'])) {
            throw new RuntimeException('The email has no content yet.');
        }

        // Its whole job is the "yes" link. Without it, nobody could answer.
        if ($campaign->kind === 'repermission' && ! str_contains(json_encode($campaign->design), '{{subscribe_url}}')) {
            throw new RuntimeException('Add a button linking to {{subscribe_url}}: it is how people say yes.');
        }
    }

    private function finishCompleted(): void
    {
        EmailCampaign::where('status', 'sending')
            // An automation step is never "finished": more people arrive.
            ->where('kind', '!=', 'automation')
            ->whereDoesntHave('sends', fn ($q) => $q->where('status', 'queued'))
            ->get()
            ->each(function (EmailCampaign $c) {
                $c->forceFill(['status' => 'sent', 'finished_at' => now()])->save();
                Credits::settle($c);
            });
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

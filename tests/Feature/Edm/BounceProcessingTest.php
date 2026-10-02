<?php

namespace Tests\Feature\Edm;

use App\Mail\CampaignMail;
use App\Mail\PlatformAlertMail;
use App\Models\EmailBounce;
use App\Models\EmailCampaign;
use App\Models\EmailConsent;
use App\Models\EmailSend;
use App\Models\EmailSuppression;
use App\Services\Edm\CampaignSender;
use App\Support\Edm\Bounces\BounceParser;
use App\Support\Edm\Bounces\BounceProcessor;
use App\Support\Edm\Bounces\ImapMailbox;
use App\Support\Edm\Bounces\ImapTransport;
use App\Support\Edm\Consent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Bounces and complaints read back from the return mailbox: parsed, tied to
 * their send, counted against the campaign, and acted on.
 */
class BounceProcessingTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'AbCdEfGhIjKlMnOpQrStUvWxYz0123456789AbCd';

    // ---- fixtures: what real servers send back -------------------------------

    /** Postfix / Gmail-style DSN, quoting the original headers. */
    private function dsn(string $to, string $status, string $diagnostic, string $action = 'failed', string $id = 'b1@mx.example'): string
    {
        $token = self::TOKEN;

        return <<<MAIL
From: Mail Delivery System <MAILER-DAEMON@mx.example>
To: promo@edm.droprsvp.com
Subject: Undelivered Mail Returned to Sender
Message-ID: <{$id}>
MIME-Version: 1.0
Content-Type: multipart/report; report-type=delivery-status;
	boundary="XYZ"

--XYZ
Content-Type: text/plain

This is the mail system at host mx.example.
I'm sorry to have to inform you that your message could not be delivered.

--XYZ
Content-Type: message/delivery-status

Reporting-MTA: dns; mx.example
Arrival-Date: Mon,  6 Oct 2026 10:00:00 +0800

Final-Recipient: rfc822; {$to}
Original-Recipient: rfc822;{$to}
Action: {$action}
Status: {$status}
Diagnostic-Code: smtp; {$diagnostic}

--XYZ
Content-Type: text/rfc822-headers

From: DropRSVP <promo@edm.droprsvp.com>
To: {$to}
Subject: Happening this week
X-Campaign: c1
X-Edm-Send: {$token}

--XYZ--
MAIL;
    }

    private function campaignWithSend(string $email = 'ghost@example.com'): EmailSend
    {
        $campaign = EmailCampaign::create(['name' => 'October', 'subject' => 'Hi', 'status' => 'sending', 'sent_count' => 1]);

        return EmailSend::create([
            'campaign_id' => $campaign->id, 'email' => $email, 'name' => 'Ghost',
            'token' => self::TOKEN, 'status' => 'sent', 'sent_at' => now()->subHour(),
        ]);
    }

    // ---- parsing ---------------------------------------------------------------

    public function test_reads_a_delivery_status_notification(): void
    {
        $r = BounceParser::parse($this->dsn('ghost@example.com', '5.1.1', '550 5.1.1 <ghost@example.com>: Recipient address rejected: User unknown'));

        $this->assertCount(1, $r);
        $this->assertSame('ghost@example.com', $r[0]['email']);
        $this->assertSame('hard', $r[0]['type']);
        $this->assertSame('5.1.1', $r[0]['status']);
        $this->assertSame(self::TOKEN, $r[0]['token']);
        $this->assertSame(1, $r[0]['campaign_id']);
        $this->assertSame('b1@mx.example', $r[0]['message_id']);
    }

    public function test_a_delay_warning_is_not_a_bounce(): void
    {
        $this->assertSame([], BounceParser::parse($this->dsn('slow@example.com', '4.4.1', '421 try again later', 'delayed')));
    }

    public function test_classification(): void
    {
        $this->assertSame('hard', BounceParser::classify('5.1.1', 'User unknown'));
        $this->assertSame('hard', BounceParser::classify('5.4.4', 'Unrouteable address'));
        $this->assertSame('soft', BounceParser::classify('5.2.2', 'Mailbox full'));
        $this->assertSame('soft', BounceParser::classify('4.2.2', 'over quota'));
        $this->assertSame('blocked', BounceParser::classify('5.7.1', 'Message rejected due to spam content'));
        $this->assertSame('blocked', BounceParser::classify('5.7.26', 'Unauthenticated email is not accepted due to DMARC policy'));
        $this->assertSame('hard', BounceParser::classify(null, '550 No such user here'));
        $this->assertSame('soft', BounceParser::classify(null, 'Connection timed out'));
    }

    public function test_reads_a_free_text_exim_bounce(): void
    {
        $raw = <<<'MAIL'
From: Mail Delivery System <Mailer-Daemon@server.example>
To: promo@edm.droprsvp.com
Subject: Mail delivery failed: returning message to sender
Message-ID: <exim1@server.example>

This message was created automatically by mail delivery software.

A message that you sent could not be delivered to one or more of its
recipients. This is a permanent error. The following address(es) failed:

  nobody@gone.example
    host mx.gone.example [1.2.3.4]
    SMTP error from remote mail server after RCPT TO:<nobody@gone.example>:
    550 5.1.1 The email account that you tried to reach does not exist.

------ This is a copy of the message, including all the headers. ------

From: DropRSVP <promo@edm.droprsvp.com>
To: nobody@gone.example
Reply-To: hello@droprsvp.com
MAIL;

        $r = BounceParser::parse($raw, ['promo@edm.droprsvp.com']);

        $this->assertCount(1, $r);
        $this->assertSame('nobody@gone.example', $r[0]['email']);
        $this->assertSame('hard', $r[0]['type']);
    }

    public function test_reads_a_spam_complaint(): void
    {
        $raw = <<<'MAIL'
From: staff@hotmail.example
Subject: complaint about message
Message-ID: <arf1@fbl.example>
MIME-Version: 1.0
Content-Type: multipart/report; report-type=feedback-report; boundary="ARF"

--ARF
Content-Type: text/plain

This is an email abuse report.

--ARF
Content-Type: message/feedback-report

Feedback-Type: abuse
User-Agent: FBL/1.0
Version: 1
Original-Rcpt-To: annoyed@example.com

--ARF
Content-Type: message/rfc822

From: promo@edm.droprsvp.com
To: annoyed@example.com
Subject: Hi

body
--ARF--
MAIL;

        $r = BounceParser::parse($raw);

        $this->assertSame('complaint', $r[0]['type']);
        $this->assertSame('annoyed@example.com', $r[0]['email']);
    }

    public function test_ordinary_mail_is_ignored(): void
    {
        $this->assertSame([], BounceParser::parse("From: friend@example.com\nSubject: Thanks!\n\nLoved the show, ping me at friend2@example.com"));
    }

    // ---- acting on it ----------------------------------------------------------

    public function test_a_hard_bounce_marks_the_send_counts_it_and_suppresses_the_address(): void
    {
        $send = $this->campaignWithSend();

        $summary = BounceProcessor::handle($this->dsn('ghost@example.com', '5.1.1', 'User unknown'));

        $this->assertSame(['bounces' => 1, 'suppressed' => 1, 'matched' => 1], $summary);
        $this->assertSame('bounced', $send->fresh()->status);
        $this->assertSame(1, $send->campaign->fresh()->bounced_count);
        $this->assertTrue(Consent::isSuppressed('ghost@example.com'));
        $this->assertSame('bounce', EmailSuppression::first()->reason);
    }

    public function test_the_same_bounce_read_twice_counts_once(): void
    {
        $send = $this->campaignWithSend();
        $raw = $this->dsn('ghost@example.com', '5.1.1', 'User unknown');

        BounceProcessor::handle($raw);
        $again = BounceProcessor::handle($raw);

        $this->assertSame(0, $again['bounces']);
        $this->assertSame(1, $send->campaign->fresh()->bounced_count);
        $this->assertSame(1, EmailBounce::count());
    }

    public function test_soft_bounces_suppress_only_after_repeats(): void
    {
        $this->campaignWithSend('full@example.com');

        BounceProcessor::handle($this->dsn('full@example.com', '5.2.2', 'Mailbox full', id: 's1@x'));
        BounceProcessor::handle($this->dsn('full@example.com', '5.2.2', 'Mailbox full', id: 's2@x'));
        $this->assertFalse(Consent::isSuppressed('full@example.com'));

        BounceProcessor::handle($this->dsn('full@example.com', '5.2.2', 'Mailbox full', id: 's3@x'));
        $this->assertTrue(Consent::isSuppressed('full@example.com'));
    }

    public function test_a_block_about_us_never_suppresses_the_reader(): void
    {
        $send = $this->campaignWithSend();

        BounceProcessor::handle($this->dsn('ghost@example.com', '5.7.1', 'Our system has detected that this message is likely spam'));

        $this->assertFalse(Consent::isSuppressed('ghost@example.com'));
        $this->assertSame(1, $send->campaign->fresh()->bounced_count); // still counts against the campaign
        $this->assertSame('blocked', EmailBounce::first()->type);
    }

    public function test_a_complaint_suppresses_unsubscribes_and_counts(): void
    {
        $send = $this->campaignWithSend('annoyed@example.com');
        Consent::grant('annoyed@example.com', 'checkout');

        BounceProcessor::handle(str_replace(
            ['ghost@example.com', 'Undelivered Mail'],
            ['annoyed@example.com', 'complaint'],
            "From: fbl@example\nSubject: complaint\nMessage-ID: <c1@x>\nMIME-Version: 1.0\nContent-Type: multipart/report; report-type=feedback-report; boundary=\"A\"\n\n--A\nContent-Type: message/feedback-report\n\nFeedback-Type: abuse\nOriginal-Rcpt-To: annoyed@example.com\n\n--A--\n",
        ));

        $this->assertTrue(Consent::isSuppressed('annoyed@example.com'));
        $this->assertSame('unsubscribed', EmailConsent::where('email', 'annoyed@example.com')->value('status'));
        $this->assertNotNull($send->fresh()->unsubscribed_at);
        $this->assertSame(1, $send->campaign->fresh()->unsubscribed_count);
    }

    public function test_bounces_now_trip_the_auto_pause(): void
    {
        Mail::fake();
        config(['edm.warmup.enabled' => false, 'edm.hourly_limit' => 6000]);

        $campaign = EmailCampaign::create(['name' => 'Big', 'subject' => 'Hi', 'status' => 'sending']);
        $campaign->forceFill(['sent_count' => 60])->save();
        EmailSend::create(['campaign_id' => $campaign->id, 'email' => 'q@example.com', 'token' => str_repeat('q', 40), 'status' => 'queued']);

        for ($i = 0; $i < 4; $i++) {
            $email = "dead{$i}@example.com";
            EmailSend::create(['campaign_id' => $campaign->id, 'email' => $email, 'token' => str_pad((string) $i, 40, 'z'), 'status' => 'sent', 'sent_at' => now()]);
            BounceProcessor::handle(str_replace([self::TOKEN, 'X-Campaign: c1'], [str_pad((string) $i, 40, 'z'), "X-Campaign: c{$campaign->id}"], $this->dsn($email, '5.1.1', 'User unknown', id: "d{$i}@x")));
        }

        app(CampaignSender::class)->dispatch();

        $this->assertSame('paused', $campaign->fresh()->status); // 4/60 = 6.7% > 5%
        $this->assertStringContainsString('Bounce rate', (string) $campaign->fresh()->paused_reason);
        // Paused before the queued email went out — and the admins were told.
        Mail::assertNotSent(CampaignMail::class);
        Mail::assertSent(PlatformAlertMail::class);
    }

    // ---- the mailbox -------------------------------------------------------------

    public function test_reads_the_mailbox_over_imap_and_marks_what_it_processed(): void
    {
        $message = $this->dsn('ghost@example.com', '5.1.1', 'User unknown');
        $transport = new ScriptedImap([
            "* OK Dovecot ready.\r\n",
            "A0001 OK Logged in\r\n",
            "* FLAGS (\\Answered \\Flagged \\Deleted \\Seen \\Draft)\r\n* OK [PERMANENTFLAGS (\\Answered \\Flagged \\Deleted \\Seen \\Draft \\*)] Flags permitted.\r\nA0002 OK [READ-WRITE] Select completed\r\n",
            "* SEARCH 7\r\nA0003 OK Search completed\r\n",
            '* 1 FETCH (UID 7 BODY[] {'.strlen($message)."}\r\n".$message.")\r\nA0004 OK Fetch completed\r\n",
            "A0005 OK Store completed\r\n",
        ]);

        $box = new ImapMailbox($transport);
        $box->login('promo@edm.droprsvp.com', 'se"cret');
        $box->select('INBOX');
        $uids = $box->unprocessed();
        $raw = $box->fetch($uids[0]);
        $box->markProcessed($uids[0]);

        $this->assertSame([7], $uids);
        $this->assertSame($message, $raw);
        $this->assertStringContainsString('LOGIN "promo@edm.droprsvp.com" "se\\"cret"', $transport->sent[0]);
        $this->assertStringContainsString('UID SEARCH UNKEYWORD EdmProcessed', $transport->sent[2]);
        $this->assertStringContainsString('BODY.PEEK[]', $transport->sent[3]);
        $this->assertStringContainsString('+FLAGS.SILENT (EdmProcessed)', $transport->sent[4]);
    }

    public function test_a_failed_login_never_echoes_the_password(): void
    {
        $box = new ImapMailbox(new ScriptedImap(["* OK ready\r\n", "A0001 NO [AUTHENTICATIONFAILED] Authentication failed.\r\n"]));

        try {
            $box->login('promo@edm.droprsvp.com', 'hunter2');
            $this->fail('Expected a failure');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Authentication failed', $e->getMessage());
            $this->assertStringNotContainsString('hunter2', $e->getMessage());
        }
    }

    public function test_the_command_says_when_it_is_not_configured(): void
    {
        config(['edm.bounces.host' => null]);

        $this->artisan('edm:bounces')->expectsOutputToContain('not configured')->assertSuccessful();
    }
}

/** An IMAP server that replies from a script and records what it was sent. */
class ScriptedImap implements ImapTransport
{
    public array $sent = [];

    private string $buffer = '';

    /** @param list<string> $replies one reply per command, after the greeting */
    public function __construct(private array $replies) {}

    public function write(string $data): void
    {
        $this->sent[] = $data;
        $this->buffer .= array_shift($this->replies) ?? '';
    }

    public function readLine(): ?string
    {
        if ($this->buffer === '' && $this->replies !== [] && $this->sent === []) {
            $this->buffer .= array_shift($this->replies); // the greeting
        }

        if ($this->buffer === '') {
            return null;
        }

        $pos = strpos($this->buffer, "\n");
        $line = $pos === false ? $this->buffer : substr($this->buffer, 0, $pos + 1);
        $this->buffer = substr($this->buffer, strlen($line));

        return $line;
    }

    public function read(int $bytes): string
    {
        $data = substr($this->buffer, 0, $bytes);
        $this->buffer = substr($this->buffer, $bytes);

        return $data;
    }

    public function close(): void {}
}

<?php

namespace App\Support\Edm\Bounces;

/**
 * Reads a returned email and says who bounced, how badly, and from which send.
 *
 * Three shapes reach the return mailbox:
 *
 *  1. Delivery Status Notifications (RFC 3464): multipart/report with a
 *     message/delivery-status part listing each recipient, its Action and
 *     Status code. What Gmail, Outlook, Postfix and current Exim send.
 *  2. Feedback reports (RFC 5965, "ARF"): multipart/report with
 *     report-type=feedback-report — someone pressed "Report spam" and their
 *     provider told us. Yahoo, AOL and others send these.
 *  3. Free-text bounces: older or hand-rolled servers that just write
 *     "A message that you sent could not be delivered … 550 5.1.1 User
 *     unknown". Recognised by sender and subject, then read for addresses and
 *     codes.
 *
 * Every campaign email carries an X-Edm-Send header with its send token, and
 * most bounces quote the original message's headers, so the bounce can be tied
 * to the exact send rather than guessed from the address.
 *
 * Classification is deliberately cautious about suppressing: only a definite
 * "this address does not exist" is hard. A refusal that is about US — policy,
 * reputation, spam filtering — is "blocked": it counts against the campaign
 * but must not cost a real reader their subscription.
 */
final class BounceParser
{
    /**
     * @return list<array{email: string, type: string, status: ?string, diagnostic: ?string, token: ?string, campaign_id: ?int, message_id: ?string}>
     */
    public static function parse(string $raw, array $ignore = []): array
    {
        $raw = str_replace("\r\n", "\n", $raw);
        [$headers, $body] = self::split($raw);

        $messageId = self::header($headers, 'message-id');
        $messageId = $messageId ? trim($messageId, " <>\t") : null;
        $token = preg_match('/^X-Edm-Send:\s*([A-Za-z0-9]{40})\s*$/mi', $raw, $m) ? $m[1] : null;
        $campaignId = preg_match('/^X-Campaign:\s*c(\d+)\s*$/mi', $raw, $m) ? (int) $m[1] : null;

        $contentType = strtolower((string) self::header($headers, 'content-type'));
        $ignore = array_map('strtolower', array_filter($ignore));

        $results = [];
        $isReport = str_contains($contentType, 'multipart/report');

        if ($isReport) {
            $parts = self::parts($contentType, self::header($headers, 'content-type'), $body);

            if (str_contains($contentType, 'feedback-report')) {
                $results = self::fromFeedbackReport($parts);
            } else {
                $results = self::fromDeliveryStatus($parts);
            }
        }

        // A structured report that lists nobody as failed (a "delayed" warning,
        // a success notice) means exactly that — never fall back to guessing.
        if ($results === [] && ! $isReport && self::looksLikeBounce($headers)) {
            $results = self::fromFreeText(self::decodedText($headers, $body), $ignore);
        }

        $out = [];
        foreach ($results as $r) {
            $email = strtolower(trim($r['email'], " <>\t;"));

            if (! filter_var($email, FILTER_VALIDATE_EMAIL) || in_array($email, $ignore, true)) {
                continue;
            }

            $out[$email] = [
                'email' => $email,
                'type' => $r['type'],
                'status' => $r['status'] ?? null,
                'diagnostic' => isset($r['diagnostic']) ? mb_substr(trim(preg_replace('/\s+/', ' ', (string) $r['diagnostic'])), 0, 500) : null,
                'token' => $token,
                'campaign_id' => $campaignId,
                'message_id' => $messageId ? mb_substr($messageId, 0, 191) : null,
            ];
        }

        return array_values($out);
    }

    /**
     * hard | soft | blocked, from an enhanced status code and the server's text.
     */
    public static function classify(?string $status, ?string $diagnostic): string
    {
        $text = strtolower((string) $diagnostic);
        $status = (string) $status;

        // About us, not the address: spam filtering, reputation, policy.
        $aboutUs = '/spam|blocked|block list|blocklist|blacklist|reputation|policy|rejected due to|not allowed|denied|dmarc|spf|dkim|authentication|unauthenticated|too many|rate limit|throttl/';
        // The address itself is gone.
        $gone = '/user unknown|unknown user|no such user|does not exist|doesn.t exist|not exist|invalid recipient|invalid address|recipient address rejected|mailbox unavailable|mailbox not found|no mailbox|address rejected|unrouteable|unroutable|account (has been )?disabled|account.*(inactive|suspended|closed)|no longer (active|available)|user not found|not a valid/';
        $full = '/quota|mailbox (is )?full|over quota|insufficient storage|storage/';

        if (preg_match('/^5\.2\.2/', $status) || preg_match($full, $text)) {
            return 'soft';
        }

        if (preg_match('/^5\.1\.\d/', $status) || preg_match('/^5\.4\.4/', $status) || preg_match('/^5\.2\.1/', $status)) {
            return 'hard';
        }

        if (preg_match('/^5\.7\.\d/', $status) || preg_match($aboutUs, $text)) {
            // A 5.7 that plainly says the mailbox does not exist is still hard.
            return preg_match($gone, $text) && ! preg_match('/spam|reputation|blocklist|blacklist/', $text) ? 'hard' : 'blocked';
        }

        if (preg_match($gone, $text)) {
            return 'hard';
        }

        if (str_starts_with($status, '4') || preg_match('/\b4\d\d\b/', $text)) {
            return 'soft';
        }

        if (str_starts_with($status, '5') || preg_match('/\b55[0-4]\b/', $text)) {
            return 'hard';
        }

        return 'soft';
    }

    // ---- shapes ------------------------------------------------------------

    /** @param list<array{headers: string, body: string}> $parts */
    private static function fromDeliveryStatus(array $parts): array
    {
        $out = [];

        foreach ($parts as $part) {
            $type = strtolower((string) self::header($part['headers'], 'content-type'));

            if (! str_contains($type, 'delivery-status')) {
                continue;
            }

            // A per-message block, then one block per recipient.
            foreach (preg_split('/\n\s*\n/', trim(self::unfold($part['body']))) as $block) {
                $action = strtolower(trim((string) self::field($block, 'action')));
                $recipient = self::field($block, 'final-recipient') ?? self::field($block, 'original-recipient');

                if (! $recipient || ($action !== '' && $action !== 'failed')) {
                    continue; // "delayed" is a warning, not a bounce; "delivered" is fine
                }

                $email = preg_replace('/^[a-z0-9.\-]+;\s*/i', '', trim($recipient));
                $status = self::field($block, 'status');
                $status = $status && preg_match('/([245]\.\d{1,3}\.\d{1,3})/', $status, $m) ? $m[1] : null;
                $diagnostic = self::field($block, 'diagnostic-code');
                $diagnostic = $diagnostic ? preg_replace('/^smtp;\s*/i', '', trim($diagnostic)) : null;

                $out[] = ['email' => $email, 'type' => self::classify($status, $diagnostic), 'status' => $status, 'diagnostic' => $diagnostic];
            }
        }

        return $out;
    }

    /** @param list<array{headers: string, body: string}> $parts */
    private static function fromFeedbackReport(array $parts): array
    {
        $email = null;
        $feedback = null;

        foreach ($parts as $part) {
            $type = strtolower((string) self::header($part['headers'], 'content-type'));

            if (str_contains($type, 'feedback-report')) {
                $block = self::unfold($part['body']);
                $email = self::field($block, 'original-rcpt-to') ?? self::field($block, 'removal-recipient');
                $feedback = self::field($block, 'feedback-type');
            } elseif (! $email && (str_contains($type, 'rfc822') || str_contains($type, 'rfc822-headers'))) {
                [$original] = self::split(ltrim($part['body']));
                $to = self::header($original, 'to');
                if ($to && preg_match('/[^\s<>,"]+@[^\s<>,"]+/', $to, $m)) {
                    $email = $m[0];
                }
            }
        }

        return $email ? [['email' => $email, 'type' => 'complaint', 'status' => null, 'diagnostic' => 'Feedback report: '.($feedback ?: 'abuse')]] : [];
    }

    private static function fromFreeText(string $text, array $ignore): array
    {
        // Where an MTA lists the failed addresses, the quoted original follows;
        // read only the part before it so the original's own To/From and any
        // addresses in its content are not taken for recipients.
        $cut = preg_split('/^-{2,}.*(original message|returned message|message headers|below this line).*$|^Original message headers:|^------ This is a copy of the message/mi', $text)[0] ?? $text;

        preg_match_all('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $cut, $m);
        $emails = array_values(array_unique(array_filter(
            array_map('strtolower', $m[0]),
            fn ($e) => ! preg_match('/^(mailer-daemon|postmaster|noreply|no-reply)@/', $e) && ! in_array($e, $ignore, true),
        )));

        $status = preg_match('/\b([45]\.\d{1,3}\.\d{1,3})\b/', $cut, $s) ? $s[1] : null;
        $diagnostic = null;
        if (preg_match('/(\b[45]\d\d[\s\-][^\n]{0,300})/', $cut, $d)) {
            $diagnostic = $d[1];
        } elseif (preg_match('/(user unknown|no such user|mailbox (is )?full|over quota|does not exist)[^\n]{0,200}/i', $cut, $d)) {
            $diagnostic = $d[0];
        }

        // A free-text bounce naming many addresses is more likely quoting a
        // mailing list than reporting failures; trust only the first few.
        return array_map(
            fn ($e) => ['email' => $e, 'type' => self::classify($status, $diagnostic), 'status' => $status, 'diagnostic' => $diagnostic],
            array_slice($emails, 0, 5),
        );
    }

    private static function looksLikeBounce(string $headers): bool
    {
        $from = strtolower((string) self::header($headers, 'from'));
        $subject = strtolower((string) self::header($headers, 'subject'));

        return (bool) preg_match('/mailer-daemon|postmaster|mail delivery (system|subsystem)/', $from)
            || (bool) preg_match('/undeliver|returned mail|delivery (status notification|failure|has failed|failed)|failure notice|mail delivery failed|could not be delivered|delivery problem/', $subject);
    }

    // ---- MIME --------------------------------------------------------------

    /** @return array{0: string, 1: string} */
    private static function split(string $raw): array
    {
        $pos = strpos($raw, "\n\n");

        return $pos === false ? [$raw, ''] : [substr($raw, 0, $pos), substr($raw, $pos + 2)];
    }

    private static function unfold(string $headers): string
    {
        return preg_replace("/\n[ \t]+/", ' ', $headers) ?? $headers;
    }

    private static function header(string $headers, string $name): ?string
    {
        return self::field(self::unfold($headers), $name);
    }

    private static function field(string $block, string $name): ?string
    {
        return preg_match('/^'.preg_quote($name, '/').':[ \t]*(.*)$/mi', $block, $m) ? trim($m[1]) : null;
    }

    /** @return list<array{headers: string, body: string}> */
    private static function parts(string $lowerType, ?string $type, string $body): array
    {
        if (! preg_match('/boundary="?([^";]+)"?/i', (string) $type, $m)) {
            return [];
        }

        $boundary = $m[1];
        $out = [];

        foreach (explode('--'.$boundary, $body) as $chunk) {
            $chunk = ltrim($chunk, "\n");

            if ($chunk === '' || str_starts_with($chunk, '--')) {
                continue;
            }

            [$h, $b] = self::split($chunk);
            $ct = strtolower((string) self::header($h, 'content-type'));

            // Nested multiparts (some MTAs wrap the human-readable part).
            if (str_starts_with($ct, 'multipart/')) {
                array_push($out, ...self::parts($ct, self::header($h, 'content-type'), $b));

                continue;
            }

            $out[] = ['headers' => $h, 'body' => self::decode($h, $b)];
        }

        return $out;
    }

    private static function decode(string $headers, string $body): string
    {
        $encoding = strtolower((string) self::header($headers, 'content-transfer-encoding'));

        return match ($encoding) {
            'base64' => (string) base64_decode(preg_replace('/\s+/', '', $body) ?? '', true),
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };
    }

    /** The readable text of a non-report bounce, decoded and with multiparts flattened. */
    private static function decodedText(string $headers, string $body): string
    {
        $type = self::header($headers, 'content-type');

        if ($type && str_contains(strtolower($type), 'multipart/')) {
            return implode("\n", array_map(fn ($p) => $p['body'], self::parts(strtolower($type), $type, $body)));
        }

        return self::decode($headers, $body);
    }
}

<?php

namespace App\Support\Edm;

use App\Models\EmailCampaign;
use App\Services\Edm\CampaignSender;

/**
 * A pre-send check of the things spam filters actually weigh, explained.
 *
 * Not a SpamAssassin run — there is no daemon on shared hosting — but the same
 * families of rules: shouting subjects, trigger phrases, image-only mail, link
 * shorteners and raw-IP links, missing preview text, a missing postal address.
 * Each finding says what to change. The score is 0–10, higher is better.
 *
 * Advisory: a "poor" score warns loudly at send time but does not block — the
 * admin knows things a heuristic cannot (an event literally called "FREE JAZZ").
 */
final class SpamCheck
{
    /** Phrases filters score, matched as whole words, case-insensitively. */
    public const TRIGGERS = [
        '100% free', 'act now', 'apply now', 'buy now', 'call now', 'cash bonus', 'click below', 'click here',
        'congratulations', 'dear friend', 'double your', 'earn money', 'exclusive deal', 'extra cash',
        'guaranteed', 'increase sales', 'lowest price', 'make money', 'miracle', 'no catch', 'no credit check',
        'no obligation', 'no risk', 'not spam', 'offer expires', 'once in a lifetime', 'order now', 'risk-free',
        'risk free', 'special promotion', 'this is not spam', 'urgent', 'winner', 'you have been selected',
        'you are a winner', 'casino', 'viagra', 'bitcoin', 'crypto', 'investment opportunity', 'weight loss',
        'cheap', 'prize', 'free gift', 'free money', 'limited time only', 'save big', '$$$',
    ];

    /** Uppercase words that are names, not shouting. */
    private const ACRONYMS = ['RSVP', 'KL', 'DJ', 'MY', 'UK', 'USA', 'FAQ', 'VIP', 'PJ', 'JB', 'BBQ', 'EDM', 'TV', 'OK', 'AM', 'PM', 'RM', 'MYR', 'QR', 'ID', 'CEO', 'LIVE'];

    private const SHORTENERS = ['bit.ly', 'tinyurl.com', 't.co', 'goo.gl', 'ow.ly', 'is.gd', 'buff.ly', 'rebrand.ly', 'cutt.ly', 'shorturl.at', 'rb.gy', 'tiny.cc', 's.id'];

    /**
     * @return array{score: float, level: string, checks: list<array{id: string, group: string, label: string, status: string, detail: string}>}
     */
    public static function forCampaign(EmailCampaign $campaign): array
    {
        $rendered = Renderer::render((array) $campaign->design, CampaignSender::renderContext($campaign));

        return self::analyse(
            subject: (string) $campaign->subject,
            preheader: (string) $campaign->preheader,
            html: $rendered['html'],
            text: $rendered['text'],
            postalAddress: (string) Settings::get('postal_address'),
            fromName: (string) ($campaign->from_name ?: Settings::get('from_name')),
        );
    }

    /**
     * @return array{score: float, level: string, checks: list<array{id: string, group: string, label: string, status: string, detail: string}>}
     */
    public static function analyse(string $subject, string $preheader, string $html, string $text, string $postalAddress, string $fromName): array
    {
        $checks = [];
        $penalty = 0.0;
        $add = function (string $id, string $group, string $label, string $status, string $detail, float $cost = 0) use (&$checks, &$penalty) {
            $checks[] = compact('id', 'group', 'label', 'status', 'detail');
            $penalty += $status === 'pass' ? 0 : $cost;
        };

        // ---- subject --------------------------------------------------------
        $subject = trim($subject);
        $letters = preg_replace('/[^A-Za-z]/', '', $subject) ?? '';
        $upper = preg_replace('/[^A-Z]/', '', $subject) ?? '';
        $shoutWords = self::shoutingWords($subject);
        $subjectTriggers = self::triggers($subject);

        if ($subject === '') {
            $add('subject-missing', 'Subject', 'Subject line', 'fail', 'Add a subject line — mail without one is almost always filtered.', 3);
        } else {
            $len = mb_strlen($subject);
            $add('subject-length', 'Subject', 'Subject length', $len < 10 || $len > 70 ? 'warn' : 'pass',
                $len > 70 ? "{$len} characters — phones cut subjects at about 40–70. Put the point first." : ($len < 10 ? 'Very short subjects look like spam or a mistake.' : "{$len} characters — a good length."), 0.5);

            if (strlen($letters) >= 6 && strlen($upper) / max(1, strlen($letters)) > 0.5) {
                $add('subject-caps', 'Subject', 'Capital letters', 'fail', 'The subject is mostly CAPITALS — one of the strongest spam signals. Use normal case.', 2);
            } else {
                $add('subject-caps', 'Subject', 'Capital letters', $shoutWords ? 'warn' : 'pass',
                    $shoutWords ? 'Shouting word'.(count($shoutWords) > 1 ? 's' : '').': '.implode(', ', array_slice($shoutWords, 0, 4)).'.' : 'Normal case.', 0.5);
            }

            $punct = preg_match('/!!|\?\?|\$\$|!\?|\?!/', $subject) || substr_count($subject, '!') > 1;
            $add('subject-punctuation', 'Subject', 'Punctuation', $punct ? 'warn' : 'pass',
                $punct ? 'Repeated "!", "?" or "$" — filters score these. One "!" at most.' : 'No excessive punctuation.', 1);

            $add('subject-triggers', 'Subject', 'Trigger phrases', $subjectTriggers ? 'warn' : 'pass',
                $subjectTriggers ? 'Contains '.self::quoteList($subjectTriggers).', which spam filters weigh. Rephrase if you can.' : 'None found.', min(2, count($subjectTriggers)));

            if (preg_match('/^\s*(re|fw|fwd)\s*:/i', $subject)) {
                $add('subject-fake-reply', 'Subject', 'Fake reply', 'fail', 'Starting with "Re:" or "Fwd:" pretends to be a conversation — filters and readers both punish it.', 2);
            }

            $emoji = preg_match_all('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $subject);
            if ($emoji > 2) {
                $add('subject-emoji', 'Subject', 'Emoji', 'warn', "{$emoji} emoji — one is plenty.", 0.5);
            }
        }

        $add('preheader', 'Subject', 'Preview text', trim($preheader) === '' ? 'warn' : 'pass',
            trim($preheader) === '' ? 'Add preview text. Without it the inbox shows the first words of the email, often "View in browser".' : 'Set.', 0.5);

        // ---- content --------------------------------------------------------
        $plain = trim(preg_replace('/\s+/', ' ', strip_tags(preg_replace('/<(style|script)[^>]*>.*?<\/\1>/is', '', $html) ?? '')) ?? '');
        $words = str_word_count($plain);
        $images = preg_match_all('/<img\b(?![^>]*width="1")[^>]*>/i', $html);
        $noAlt = preg_match_all('/<img\b(?![^>]*width="1")(?![^>]*\balt="[^"]+")[^>]*>/i', $html);
        preg_match_all('/href="([^"]+)"/i', $html, $m);
        $links = array_values(array_filter($m[1], fn ($u) => ! str_starts_with($u, 'mailto:') && ! str_contains($u, '{{')));
        $bodyTriggers = self::triggers($plain);
        $bodyShout = self::shoutingWords($plain);

        $add('text-amount', 'Content', 'Amount of text', $words < 50 ? 'warn' : 'pass',
            $words < 50 ? "Only {$words} words. Mail that is mostly images or very short is a classic spam pattern — add a few sentences." : "{$words} words.", 1);

        if ($images > 0) {
            $perImage = intdiv($words, $images);
            $add('image-ratio', 'Content', 'Images vs text', $images >= 2 && $perImage < 60 ? 'warn' : 'pass',
                $images >= 2 && $perImage < 60 ? "{$images} images with little text between them. Many inboxes hide images by default; make sure the words carry the message." : "{$images} image(s), with enough text.", 1);
            $add('image-alt', 'Content', 'Image descriptions', $noAlt > 0 ? 'warn' : 'pass',
                $noAlt > 0 ? "{$noAlt} image(s) without alt text. It is what shows when images are blocked." : 'Every image has alt text.', 0.25);
        }

        $shortened = array_values(array_filter($links, fn ($u) => in_array(strtolower((string) parse_url($u, PHP_URL_HOST)), self::SHORTENERS, true)));
        $rawIp = array_values(array_filter($links, fn ($u) => filter_var((string) parse_url($u, PHP_URL_HOST), FILTER_VALIDATE_IP)));
        $insecure = array_values(array_filter($links, fn ($u) => str_starts_with(strtolower($u), 'http://')));

        $add('links-shorteners', 'Links', 'Link shorteners', $shortened ? 'fail' : 'pass',
            $shortened ? 'Shortened links ('.parse_url($shortened[0], PHP_URL_HOST).') hide where they go and are heavily penalised. Use the full address.' : 'None.', 2);
        if ($rawIp) {
            $add('links-ip', 'Links', 'Links to IP addresses', 'fail', 'A link points to a bare IP address — a phishing signature. Link to a domain.', 2);
        }
        $add('links-https', 'Links', 'Secure links', $insecure ? 'warn' : 'pass',
            $insecure ? count($insecure).' link(s) use http://. Use https://.' : 'All links use https.', 0.5);
        $add('links-count', 'Links', 'Number of links', count($links) > 20 ? 'warn' : 'pass',
            count($links) > 20 ? count($links).' links. Fewer, clearer links read better and score better.' : count($links).' link(s).', 0.5);

        if (preg_match('/>\s*(click here|here)\s*</i', $html)) {
            $add('links-click-here', 'Links', 'Link text', 'warn', '"Click here" as link text is a spam signal and tells the reader nothing. Say where it goes ("See the line-up").', 0.5);
        }

        $add('body-triggers', 'Content', 'Trigger phrases', count($bodyTriggers) >= 6 ? 'fail' : (count($bodyTriggers) >= 3 ? 'warn' : 'pass'),
            $bodyTriggers ? 'Found '.self::quoteList($bodyTriggers).'.'.(count($bodyTriggers) >= 3 ? ' Several together add up.' : ' One or two is fine.') : 'None found.', count($bodyTriggers) >= 6 ? 2 : (count($bodyTriggers) >= 3 ? 1 : 0));
        $add('body-caps', 'Content', 'Capital letters', count($bodyShout) > 5 ? 'warn' : 'pass',
            count($bodyShout) > 5 ? count($bodyShout).' words in CAPITALS. Use bold for emphasis instead.' : 'Normal case.', 0.5);

        if (preg_match('/!!|\$\$/', $plain)) {
            $add('body-punctuation', 'Content', 'Punctuation', 'warn', 'Repeated "!!" or "$$" in the text.', 0.5);
        }

        // ---- compliance -------------------------------------------------------
        $add('postal-address', 'Compliance', 'Postal address', trim($postalAddress) === '' ? 'fail' : 'pass',
            trim($postalAddress) === '' ? 'No postal address in the footer. Commercial email must carry one; add it in EDM → Settings.' : 'In the footer.', 1.5);
        $add('unsubscribe', 'Compliance', 'Unsubscribe', str_contains($html, '{{unsubscribe_url}}') || stripos($html, 'unsubscribe') !== false ? 'pass' : 'fail',
            'Every email carries a one-click unsubscribe link and header.', 3);
        $add('from-name', 'Compliance', 'Sender name', trim($fromName) === '' ? 'warn' : 'pass',
            trim($fromName) === '' ? 'Set a "From" name people recognise.' : "From “{$fromName}”.", 0.5);

        $personal = str_contains($subject.$html, '{{first_name}}') || str_contains($subject.$html, '{{name}}');
        $add('personal', 'Content', 'Personalisation', 'pass', $personal ? 'Uses the reader’s name.' : 'Tip: {{first_name}} in the greeting tends to lift opens.');

        $score = round(max(0, min(10, 10 - $penalty)), 1);

        return [
            'score' => $score,
            'level' => $score >= 8 ? 'good' : ($score >= 5 ? 'fair' : 'poor'),
            'checks' => $checks,
        ];
    }

    /** @return list<string> */
    private static function triggers(string $text): array
    {
        $found = [];
        foreach (self::TRIGGERS as $phrase) {
            if (preg_match('/(?<![\w])'.preg_quote($phrase, '/').'(?![\w])/i', $text)) {
                $found[] = $phrase;
            }
        }

        // "free" on its own is everywhere on an events site ("free entry"), so it
        // only counts when shouted or repeated.
        if (preg_match('/\bFREE\b/', $text) || preg_match_all('/\bfree\b/i', $text) >= 3) {
            $found[] = 'free';
        }

        return array_values(array_unique($found));
    }

    /** @return list<string> */
    private static function shoutingWords(string $text): array
    {
        preg_match_all('/\b[A-Z]{4,}\b/', $text, $m);

        return array_values(array_unique(array_filter($m[0], fn ($w) => ! in_array($w, self::ACRONYMS, true))));
    }

    private static function quoteList(array $items): string
    {
        $items = array_slice($items, 0, 4);

        return implode(', ', array_map(fn ($i) => '“'.$i.'”', $items));
    }
}

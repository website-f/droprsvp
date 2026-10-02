<?php

namespace App\Support\Edm;

use App\Models\EmailCampaign;
use App\Models\EmailLink;
use App\Models\EmailSend;

/**
 * Per-recipient finishing: merge tags, tracked links and the open pixel.
 *
 * The campaign is rendered once and frozen (Renderer). Two passes then happen:
 *
 *  - prepareLinks(), once per campaign when it starts: every web link in the
 *    body is registered as an EmailLink and replaced by a {{click:ID}}
 *    placeholder. Doing it once means one row per link, not per recipient.
 *
 *  - forRecipient(), per send: placeholders become that recipient's tracked
 *    URL, merge tags get their values, and the open pixel is added.
 *
 * Every tracked URL carries the send's random token, never an email address
 * or user id, so a forwarded email or a URL in a log reveals nothing.
 */
final class Personalizer
{
    /** Merge tags an author may use in the subject and body. */
    public const TAGS = ['first_name', 'name', 'email'];

    /**
     * Register the campaign's links and replace them with placeholders.
     * Merge-tag hrefs ({{unsubscribe_url}}, {{view_url}}) are left alone, as
     * is mailto: — tracking those would be meaningless or break them.
     */
    public static function prepareLinks(EmailCampaign $campaign, string $html): string
    {
        return preg_replace_callback(
            '/href="(https?:\/\/[^"]+)"/i',
            function (array $m) use ($campaign) {
                $url = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');

                $link = EmailLink::firstOrCreate(
                    ['campaign_id' => $campaign->id, 'hash' => sha1($url)],
                    ['url' => $url],
                );

                return 'href="{{click:'.$link->id.'}}"';
            },
            $html,
        ) ?? $html;
    }

    /** @return array{subject:string,html:string,text:string} */
    public static function forRecipient(EmailCampaign $campaign, EmailSend $send): array
    {
        $values = self::values($send);
        $token = $send->token;

        $html = (string) $campaign->html;

        // Tracked links first, so a merge tag inside a URL cannot collide.
        $html = preg_replace_callback(
            '/\{\{click:(\d+)\}\}/',
            fn (array $m) => e(route('edm.click', ['token' => $token, 'link' => $m[1]])),
            $html,
        ) ?? $html;

        $html = self::fill($html, $values, true);

        // The open pixel. Many clients block images, so opens are a lower
        // bound — a click also counts as an open (see the tracking controller).
        $pixel = '<img src="'.e(route('edm.open', ['token' => $token])).'" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0;opacity:0;">';
        $html = str_contains($html, '</body>')
            ? str_replace('</body>', $pixel.'</body>', $html)
            : $html.$pixel;

        return [
            'subject' => self::fill((string) $campaign->subject, $values, false),
            'html' => $html,
            'text' => self::fill((string) $campaign->text, $values, false),
        ];
    }

    /** The HTML for "view in browser": the same email, without the pixel. */
    public static function forBrowser(EmailCampaign $campaign, EmailSend $send): string
    {
        $html = preg_replace_callback(
            '/\{\{click:(\d+)\}\}/',
            fn (array $m) => e(route('edm.click', ['token' => $send->token, 'link' => $m[1]])),
            (string) $campaign->html,
        ) ?? '';

        return self::fill($html, self::values($send), true);
    }

    /** @return array<string,string> */
    private static function values(EmailSend $send): array
    {
        $name = trim((string) $send->name);
        $first = $name !== '' ? strtok($name, ' ') : '';

        // An automation's per-recipient values ({{event_name}}, {{event_url}}…)
        // first, so the fixed ones below can never be overridden by them.
        $context = array_map('strval', array_filter((array) $send->context, 'is_scalar'));

        return array_merge($context, [
            // "there" reads naturally in "Hi {{first_name}}," when we have no name.
            'first_name' => $first ?: 'there',
            'name' => $name ?: 'there',
            'email' => $send->email,
            'unsubscribe_url' => route('edm.unsubscribe', ['token' => $send->token]),
            // The re-permission email's "yes" button.
            'subscribe_url' => route('edm.subscribe', ['token' => $send->token]),
            'view_url' => route('edm.view', ['token' => $send->token]),
        ]);
    }

    private static function fill(string $content, array $values, bool $html): string
    {
        return preg_replace_callback(
            '/\{\{\s*([a-z_]+)\s*\}\}/',
            function (array $m) use ($values, $html) {
                if (! array_key_exists($m[1], $values)) {
                    return $m[0];
                }

                // Escaped in HTML, so a name like "<b>Ali</b>" is shown, not run.
                return $html ? e($values[$m[1]]) : $values[$m[1]];
            },
            $content,
        ) ?? $content;
    }
}

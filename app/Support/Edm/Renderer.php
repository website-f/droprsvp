<?php

namespace App\Support\Edm;

use App\Models\Event;
use App\Support\SiteContent;
use App\Support\Url;

/**
 * Turn a campaign design (the editor's block JSON) into an email.
 *
 * The editor is the same Puck builder the CMS uses, but its blocks are not
 * rendered by React here: email clients run no JavaScript, ignore <style>
 * blocks (Gmail) and ignore most of CSS layout (Outlook). So this is the source
 * of truth for what lands in an inbox — table-based layout, every style
 * inline, a 600px column, system fonts — and the editor canvas is only a
 * close preview of it. "Preview" in the admin shows THIS output.
 *
 * The footer is not a block. It is appended here, always: who sent it, the
 * postal address, why the reader is getting it, and the unsubscribe link.
 * A campaign cannot remove those by deleting a block, because Gmail's bulk
 * rules, PDPA, and basic decency all require them.
 *
 * Per-recipient values ({{first_name}}, {{unsubscribe_url}}, …) are left as
 * merge tags for Personalizer to fill, so one render serves every recipient.
 */
final class Renderer
{
    public const DEFAULT_BRAND = '#6d28d9';

    private const FONT = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";

    /**
     * @param  array{root?:array,content?:array}  $design
     * @param  array{subject?:string,preheader?:string,sender?:string,address?:string,reason?:string}  $context
     * @return array{html:string,text:string}
     */
    public static function render(array $design, array $context = []): array
    {
        $root = (array) ($design['root']['props'] ?? []);
        $brand = self::color($root['brandColor'] ?? null, self::DEFAULT_BRAND);
        $background = self::color($root['backgroundColor'] ?? null, '#f3f4f6');

        $htmlBlocks = [];
        $textBlocks = [];

        foreach ((array) ($design['content'] ?? []) as $block) {
            if (! is_array($block)) {
                continue;
            }

            [$html, $text] = self::block((string) ($block['type'] ?? ''), (array) ($block['props'] ?? []), $brand);

            if ($html !== '') {
                $htmlBlocks[] = $html;
                $textBlocks[] = $text;
            }
        }

        $logo = ! empty($root['showLogo']) ? self::logo() : '';

        return [
            'html' => self::document(
                implode("\n", $htmlBlocks),
                $logo,
                $background,
                $brand,
                $context,
            ),
            'text' => trim(implode("\n\n", array_filter($textBlocks))).self::textFooter($context),
        ];
    }

    /** @return array{0:string,1:string} [html, text] */
    private static function block(string $type, array $p, string $brand): array
    {
        return match ($type) {
            'Heading' => self::heading($p),
            'Text' => self::text($p, $brand),
            'Button' => self::button($p, $brand),
            'Image' => self::image($p),
            'Divider' => ['<tr><td style="padding:8px 0;"><div style="border-top:1px solid #e5e7eb;font-size:0;line-height:0;">&nbsp;</div></td></tr>', '———'],
            'Spacer' => self::spacer($p),
            'EventCard' => self::eventCard($p, $brand),
            // An unknown block type (an older or newer editor) renders nothing
            // rather than failing the whole campaign.
            default => ['', ''],
        };
    }

    private static function heading(array $p): array
    {
        $text = trim((string) ($p['text'] ?? ''));

        if ($text === '') {
            return ['', ''];
        }

        $size = ($p['level'] ?? 'h2') === 'h1' ? 28 : 21;
        $align = self::align($p['align'] ?? null);

        return [
            '<tr><td style="padding:0 0 12px 0;text-align:'.$align.';font-family:'.self::FONT.';font-size:'.$size.'px;line-height:1.25;font-weight:700;color:#111827;">'.e($text).'</td></tr>',
            mb_strtoupper($text),
        ];
    }

    private static function text(array $p, string $brand): array
    {
        $html = EmailHtml::clean((string) ($p['html'] ?? ''), $brand);

        if ($html === '') {
            return ['', ''];
        }

        $align = self::align($p['align'] ?? null);

        return [
            '<tr><td style="padding:0 0 4px 0;text-align:'.$align.';font-family:'.self::FONT.';font-size:15px;line-height:1.6;color:#374151;">'.$html.'</td></tr>',
            EmailHtml::toText($html),
        ];
    }

    private static function button(array $p, string $brand): array
    {
        $label = trim((string) ($p['label'] ?? ''));
        $url = EmailHtml::safeHref($p['url'] ?? '');

        if ($label === '' || $url === '#') {
            return ['', ''];
        }

        $color = self::color($p['color'] ?? null, $brand);
        $align = self::align($p['align'] ?? 'center');

        // A table cell with a background, not a styled <a> alone: Outlook drops
        // padding and backgrounds on inline elements, leaving a bare link.
        $html = '<tr><td style="padding:8px 0 16px 0;" align="'.$align.'">'
            .'<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>'
            .'<td style="border-radius:999px;background:'.$color.';" bgcolor="'.$color.'">'
            .'<a href="'.e($url).'" target="_blank" style="display:inline-block;padding:13px 28px;font-family:'.self::FONT.';font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:999px;">'.e($label).'</a>'
            .'</td></tr></table></td></tr>';

        return [$html, "{$label}: {$url}"];
    }

    private static function image(array $p): array
    {
        $src = self::imageUrl($p['src'] ?? null);

        if (! $src) {
            return ['', ''];
        }

        $alt = trim((string) ($p['alt'] ?? ''));
        $img = '<img src="'.e($src).'" alt="'.e($alt).'" width="536" style="display:block;width:100%;max-width:536px;height:auto;border:0;border-radius:10px;">';

        $href = EmailHtml::safeHref($p['href'] ?? '');

        if ($href !== '#') {
            $img = '<a href="'.e($href).'" target="_blank">'.$img.'</a>';
        }

        return ['<tr><td style="padding:0 0 16px 0;">'.$img.'</td></tr>', $alt !== '' ? "[{$alt}]" : ''];
    }

    private static function spacer(array $p): array
    {
        $height = match ($p['size'] ?? 'md') {
            'sm' => 8,
            'lg' => 40,
            default => 20,
        };

        return ['<tr><td style="height:'.$height.'px;font-size:0;line-height:0;">&nbsp;</td></tr>', ''];
    }

    /**
     * A live DropRSVP event, pulled in by slug.
     *
     * The reason this builder is worth having over a generic one: the editor
     * picks an event and the card fills itself — artwork, date in the event's
     * own timezone, venue, the lowest price — so a campaign never carries a
     * stale, retyped date. Resolved when the campaign is rendered for sending,
     * then frozen with it: a later edit to the event does not rewrite mail
     * already delivered.
     */
    private static function eventCard(array $p, string $brand): array
    {
        $slug = trim((string) ($p['event'] ?? ''));

        if ($slug === '') {
            return ['', ''];
        }

        $event = Event::query()
            ->where('slug', $slug)
            ->where('status', 'published')
            ->with('ticketTypes:id,event_id,price,kind,is_active')
            ->first();

        // Unpublished or deleted since the campaign was written: leave it out
        // rather than advertise something nobody can buy.
        if (! $event) {
            return ['', ''];
        }

        $url = Url::slash(Url::to('e', $event->slug));
        $when = $event->starts_at?->setTimezone($event->timezone ?: config('app.display_timezone', 'Asia/Kuala_Lumpur'))
            ->format('D, j M Y · g:ia');
        $where = $event->is_online ? 'Online' : collect([$event->venue_name, $event->city])->filter()->implode(', ');
        $prices = $event->ticketTypes->where('is_active', true)->pluck('price')->map(fn ($v) => (float) $v);
        $price = $prices->isEmpty() ? null : ($prices->max() <= 0 ? 'Free' : 'From RM '.number_format($prices->filter(fn ($v) => $v > 0)->min(), 2));
        $image = self::imageUrl($event->cover_image ?: $event->banner_image);
        $label = trim((string) ($p['buttonLabel'] ?? '')) ?: 'Get tickets';

        $html = '<tr><td style="padding:0 0 18px 0;">'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #e5e7eb;border-radius:12px;">'
            .($image ? '<tr><td style="padding:0;"><a href="'.e($url).'" target="_blank"><img src="'.e($image).'" alt="'.e($event->title).'" width="534" style="display:block;width:100%;max-width:534px;height:auto;border:0;border-radius:12px 12px 0 0;"></a></td></tr>' : '')
            .'<tr><td style="padding:18px 20px 20px 20px;font-family:'.self::FONT.';">'
            .'<div style="font-size:18px;line-height:1.3;font-weight:700;color:#111827;margin:0 0 6px 0;">'.e($event->title).'</div>'
            .($when ? '<div style="font-size:14px;line-height:1.5;color:#4b5563;">'.e($when).'</div>' : '')
            .($where ? '<div style="font-size:14px;line-height:1.5;color:#4b5563;">'.e($where).'</div>' : '')
            .($price ? '<div style="font-size:14px;line-height:1.5;font-weight:700;color:#111827;margin:6px 0 0 0;">'.e($price).'</div>' : '')
            .'<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:14px 0 0 0;"><tr>'
            .'<td style="border-radius:999px;background:'.$brand.';" bgcolor="'.$brand.'"><a href="'.e($url).'" target="_blank" style="display:inline-block;padding:11px 22px;font-size:14px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:999px;">'.e($label).'</a></td>'
            .'</tr></table>'
            .'</td></tr></table></td></tr>';

        $text = implode("\n", array_filter([$event->title, $when, $where, $price, "{$label}: {$url}"]));

        return [$html, $text];
    }

    private static function document(string $blocks, string $logo, string $background, string $brand, array $c): string
    {
        $preheader = e((string) ($c['preheader'] ?? ''));
        $subject = e((string) ($c['subject'] ?? ''));

        // Pad the preheader so the inbox preview does not run on into the
        // first line of body copy after it.
        $padding = str_repeat('&#847;&zwnj;&nbsp;', 40);

        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<meta name="x-apple-disable-message-reformatting">'
            .'<title>'.$subject.'</title></head>'
            .'<body style="margin:0;padding:0;background:'.$background.';">'
            .'<div style="display:none;max-height:0;max-width:0;overflow:hidden;opacity:0;mso-hide:all;">'.$preheader.$padding.'</div>'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:'.$background.';" bgcolor="'.$background.'">'
            .'<tr><td align="center" style="padding:24px 12px;">'
            .'<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;background:#ffffff;border-radius:14px;" bgcolor="#ffffff">'
            .'<tr><td style="padding:28px 32px 20px 32px;">'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">'
            .$logo
            .$blocks
            .'</table></td></tr></table>'
            .self::htmlFooter($c, $brand)
            .'</td></tr></table></body></html>';
    }

    private static function htmlFooter(array $c, string $brand): string
    {
        $style = 'font-family:'.self::FONT.';font-size:12px;line-height:1.6;color:#6b7280;';
        $link = 'color:#6b7280;text-decoration:underline;';

        return '<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;">'
            .'<tr><td align="center" style="padding:18px 24px 8px 24px;'.$style.'">'
            .e((string) ($c['reason'] ?? 'You are receiving this because you opted in to emails from DropRSVP.'))
            .'<br><a href="{{unsubscribe_url}}" target="_blank" style="'.$link.'">Unsubscribe</a>'
            .' &nbsp;·&nbsp; <a href="{{view_url}}" target="_blank" style="'.$link.'">View in browser</a>'
            .'<br>'.e((string) ($c['sender'] ?? 'DropRSVP'))
            .(! empty($c['address']) ? ' · '.e((string) $c['address']) : '')
            .'</td></tr></table>';
    }

    private static function textFooter(array $c): string
    {
        return "\n\n--\n"
            .($c['reason'] ?? 'You are receiving this because you opted in to emails from DropRSVP.')."\n"
            ."Unsubscribe: {{unsubscribe_url}}\n"
            .($c['sender'] ?? 'DropRSVP')
            .(! empty($c['address']) ? ' · '.$c['address'] : '');
    }

    private static function logo(): string
    {
        $logo = self::imageUrl(SiteContent::branding()['logo_full'] ?? '/logo-full.png');

        return $logo
            ? '<tr><td style="padding:0 0 22px 0;"><img src="'.e($logo).'" alt="DropRSVP" height="32" style="display:block;height:32px;width:auto;border:0;"></td></tr>'
            : '';
    }

    /** An absolute http(s) image URL, or null. Images in mail need full URLs. */
    private static function imageUrl(?string $src): ?string
    {
        $src = trim((string) $src);

        if ($src === '') {
            return null;
        }

        if (str_starts_with($src, '/') && ! str_starts_with($src, '//')) {
            return url($src);
        }

        return in_array(strtolower((string) parse_url($src, PHP_URL_SCHEME)), ['http', 'https'], true) ? $src : null;
    }

    private static function color(mixed $value, string $fallback): string
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $value) ? $value : $fallback;
    }

    private static function align(mixed $value): string
    {
        return in_array($value, ['left', 'center', 'right'], true) ? $value : 'left';
    }
}

<?php

namespace App\Support;

use App\Models\Setting;

/**
 * The superadmin-editable receipt / invoice template — branding, content and
 * layout options that drive the shared receipts.pdf Blade. Stored as a single
 * `receipt_template` setting, merged over sensible defaults.
 */
class ReceiptTemplate
{
    /** @var array<string, mixed> */
    public const DEFAULTS = [
        'accent' => '#27272a',       // document number + total colour
        'logo' => '',                // uploaded logo URL
        'show_logo' => false,
        'logo_align' => 'left',      // left | right
        'title' => '',               // blank → the document's own title (Receipt / Payout receipt)
        'header_note' => '',         // a line under the header, e.g. "Thank you for your purchase!"
        'notes' => '',               // terms / notes block above the footer
        'footer_note' => 'powered by DropRSVP',
        'show_status' => true,       // the paid / refunded badge
        'show_context' => true,      // the "For {event}" block
        'show_seller_detail' => true, // the organizer detail line
        'show_tax' => true,          // the tax row (when tax > 0)
    ];

    /** @return array<string, mixed> */
    public static function get(): array
    {
        $saved = Setting::getArray('receipt_template', []);
        $out = [];
        foreach (self::DEFAULTS as $key => $default) {
            $out[$key] = is_bool($default) ? (bool) ($saved[$key] ?? $default) : ($saved[$key] ?? $default);
        }

        return $out;
    }

    /** get() with the logo inlined as a data URI — dompdf can't fetch remote images on shared hosting. */
    public static function resolved(): array
    {
        $t = self::get();

        if ($t['show_logo'] && $t['logo'] !== '') {
            $uri = self::logoDataUri($t['logo']);
            $t['logo'] = $uri ?? '';
            $t['show_logo'] = $uri !== null;
        }

        return $t;
    }

    /** Persist only the known keys, coercing booleans. */
    public static function save(array $input): void
    {
        $out = [];
        foreach (self::DEFAULTS as $key => $default) {
            if (is_bool($default)) {
                $out[$key] = (bool) ($input[$key] ?? false);
            } else {
                $out[$key] = (string) ($input[$key] ?? $default);
            }
        }
        $out['accent'] = $out['accent'] ?: '#27272a';
        $out['footer_note'] = $out['footer_note'] ?: 'powered by DropRSVP';
        $out['logo_align'] = in_array($out['logo_align'], ['left', 'right'], true) ? $out['logo_align'] : 'left';

        Setting::putArray('receipt_template', $out);
    }

    /** Turn a local /uploads or /storage logo path into a base64 data URI, or null. */
    private static function logoDataUri(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: $url;
        $file = public_path(ltrim($path, '/'));
        if (! is_file($file)) {
            return null;
        }

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif', 'svg' => 'image/svg+xml'][$ext] ?? 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($file));
    }
}

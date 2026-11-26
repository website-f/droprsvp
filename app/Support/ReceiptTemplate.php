<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;

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

    /**
     * get() with the logo inlined as a data URI — dompdf cannot fetch remote
     * images on shared hosting.
     *
     * $fallbackLogo is the seller's own logo. The template logo is an optional
     * admin override that defaults to OFF, so without this the downloaded PDF
     * came out unbranded while the on-screen receipt — which reads the seller's
     * logo directly — showed it. Two views of one document disagreeing.
     */
    public static function resolved(?string $fallbackLogo = null, ?string $lastResort = null): array
    {
        $t = self::get();

        // The admin's template logo, else the seller's, else the site's own —
        // the first that can actually be read. Receipts used to come out with
        // no logo at all when the seller had none, or when theirs lived under
        // /storage on a host without the public/storage symlink.
        $candidates = [$t['show_logo'] && $t['logo'] !== '' ? $t['logo'] : null, $fallbackLogo, $lastResort];
        $t['logo'] = '';
        $t['show_logo'] = false;

        foreach (array_filter($candidates) as $source) {
            if ($uri = self::logoDataUri((string) $source)) {
                $t['logo'] = $uri;
                $t['show_logo'] = true;
                break;
            }
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

    /**
     * A logo as a base64 data URI (dompdf cannot fetch URLs on shared hosting),
     * or null when it cannot be read.
     *
     * Our own files are read from disk: public/, or storage/app/public for a
     * /storage/... path (which only resolves under public/ when the storage
     * symlink exists — often not on cPanel). Anything else (a Google avatar)
     * is fetched once, briefly.
     */
    public static function logoDataUri(string $url): ?string
    {
        $remote = (bool) preg_match('#^https?://#i', $url);
        $path = ltrim((string) (parse_url($url, PHP_URL_PATH) ?: $url), '/');
        $sameSite = ! $remote || parse_url($url, PHP_URL_HOST) === parse_url((string) config('app.url'), PHP_URL_HOST);
        $mimes = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif', 'svg' => 'image/svg+xml'];

        if ($sameSite && $path !== '') {
            $files = [public_path($path), str_starts_with($path, 'storage/') ? storage_path('app/public/'.substr($path, 8)) : null];
            foreach (array_filter($files) as $file) {
                if (is_file($file)) {
                    $mime = $mimes[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? 'image/png';

                    return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($file));
                }
            }
        }

        if (! $remote) {
            return null;
        }

        try {
            $res = Http::timeout(4)->get($url);
            $type = strtolower(trim(explode(';', (string) $res->header('Content-Type'))[0]));

            return $res->successful() && in_array($type, $mimes, true) && strlen($res->body()) < 2_000_000
                ? 'data:'.$type.';base64,'.base64_encode($res->body())
                : null;
        } catch (\Throwable) {
            return null;
        }
    }
}

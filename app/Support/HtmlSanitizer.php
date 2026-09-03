<?php

namespace App\Support;

/**
 * Defence-in-depth sanitiser for admin-authored HTML (CMS rich text + the Puck
 * "html" block). It strips the active-content XSS vectors — <script> blocks,
 * inline event handlers, and javascript:/vbscript: URLs — while leaving benign
 * markup (styles, images, legitimate iframe embeds) intact so authored content
 * isn't mangled. This is not a substitute for a full allow-list purifier; it's a
 * safety net over content only superadmin/staff can write.
 */
class HtmlSanitizer
{
    public static function clean(?string $html): string
    {
        if (! is_string($html) || $html === '') {
            return (string) $html;
        }

        // Remove <script>…</script> blocks and any stray script tags.
        $html = preg_replace('#<\s*script\b[^>]*>.*?<\s*/\s*script\s*>#is', '', $html) ?? $html;
        $html = preg_replace('#<\s*/?\s*script\b[^>]*>#i', '', $html) ?? $html;

        // Strip inline event handlers (onclick=, onerror=, onload=, …).
        $html = preg_replace('#\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html) ?? $html;

        // Neutralise javascript:/vbscript: URLs in href/src/xlink:href.
        $html = preg_replace('#\b(href|src|xlink:href)\s*=\s*("|\')\s*(?:javascript|vbscript)\s*:[^"\']*\2#i', '$1=$2#$2', $html) ?? $html;

        return $html;
    }

    /** Recursively clean every HTML-looking string in a nested array (e.g. Puck data). */
    public static function cleanTree(array $data): array
    {
        array_walk_recursive($data, function (&$value) {
            if (is_string($value) && str_contains($value, '<')) {
                $value = self::clean($value);
            }
        });

        return $data;
    }
}

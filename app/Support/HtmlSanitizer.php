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

    /** Inline declarations that set a colour. */
    private const COLOUR_PROPERTIES = [
        'color', 'background', 'background-color', 'border-color', 'outline-color',
        'text-decoration-color', 'caret-color', 'scrollbar-color', 'column-rule-color',
        '-webkit-text-fill-color', '-webkit-text-stroke-color',
    ];

    /**
     * Drop inline colours from text an organizer wrote in the rich editor.
     *
     * Copying text off a page (this site in dark mode, another site, a doc)
     * and pasting it into a contenteditable makes the browser keep how it
     * looked there: every run arrives as <span style="color: …">. Text copied
     * in dark mode carried the dark theme's near-white, and on the light event
     * page it was white on white. The editor offers no colour tool, so a
     * colour in a description only ever got there by accident; without one the
     * text follows the page's theme in both modes.
     */
    public static function withoutColours(?string $html): ?string
    {
        if (! is_string($html) || $html === '' || ! str_contains($html, '<')) {
            return $html;
        }

        $html = preg_replace_callback('#\sstyle\s*=\s*("([^"]*)"|\'([^\']*)\')#i', function (array $m) {
            $css = html_entity_decode($m[2] !== '' ? $m[2] : ($m[3] ?? ''), ENT_QUOTES | ENT_HTML5);

            $kept = array_filter(array_map('trim', explode(';', $css)), function (string $declaration) {
                $property = strtolower(trim(strstr($declaration, ':', true) ?: ''));

                return $declaration !== '' && ! in_array($property, self::COLOUR_PROPERTIES, true);
            });

            return $kept === [] ? '' : ' style="'.htmlspecialchars(implode('; ', $kept).';', ENT_QUOTES | ENT_HTML5).'"';
        }, $html) ?? $html;

        // The older form of the same thing: <font color="…">.
        return preg_replace('#(<font\b[^>]*?)\s+color\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '$1', $html) ?? $html;
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

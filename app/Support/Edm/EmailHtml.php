<?php

namespace App\Support\Edm;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Turn rich text from the editor into HTML that is safe AND survives email.
 *
 * Two jobs, done in one pass over the DOM:
 *
 *  1. An ALLOW-list, not the site's deny-list sanitiser. That one is a safety
 *     net over content only staff can write. Campaign copy will be written by
 *     organizers too, and it is published on a public "view in browser" page —
 *     so anything not explicitly permitted is dropped: unknown tags are
 *     unwrapped (their text kept), every attribute except a vetted href is
 *     removed, and an href that is not http(s) or mailto becomes "#".
 *
 *  2. Inline styles. Gmail and Outlook ignore <style> blocks and class names,
 *     so a paragraph only gets its spacing and a link only gets its colour if
 *     the style is written on the element itself.
 */
final class EmailHtml
{
    /** tag => inline style it is given. */
    private const ALLOWED = [
        'p' => 'margin:0 0 14px 0;',
        'br' => '',
        'strong' => 'font-weight:700;',
        'b' => 'font-weight:700;',
        'em' => 'font-style:italic;',
        'i' => 'font-style:italic;',
        'u' => 'text-decoration:underline;',
        's' => 'text-decoration:line-through;',
        'a' => '',  // colour is per-campaign, filled in below
        'ul' => 'margin:0 0 14px 0;padding:0 0 0 22px;',
        'ol' => 'margin:0 0 14px 0;padding:0 0 0 22px;',
        'li' => 'margin:0 0 6px 0;',
        'hr' => 'border:0;border-top:1px solid #e5e7eb;margin:18px 0;',
        'h2' => 'margin:0 0 10px 0;font-size:20px;line-height:1.3;font-weight:700;',
        'h3' => 'margin:0 0 8px 0;font-size:17px;line-height:1.35;font-weight:700;',
        'blockquote' => 'margin:0 0 14px 0;padding:0 0 0 14px;border-left:3px solid #e5e7eb;color:#4b5563;',
    ];

    /** Tags whose content is dropped entirely, not just the tag. */
    private const DROP_WITH_CONTENT = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'svg', 'math', 'template', 'noscript', 'head', 'title', 'meta', 'link'];

    public static function clean(?string $html, string $linkColor = '#6d28d9'): string
    {
        $html = trim((string) $html);

        if ($html === '') {
            return '';
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        // The meta charset makes libxml read the input as UTF-8 rather than
        // Latin-1, which would mangle every non-ASCII character (é, —, emoji).
        $doc->loadHTML(
            '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body><div id="edm-root">'.$html.'</div></body></html>',
            LIBXML_NONET,
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $doc->getElementById('edm-root');

        if (! $root) {
            return '';
        }

        self::walk($root, $linkColor);

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $doc->saveHTML($child);
        }

        return trim($out);
    }

    /** Plain-text rendering of the same content, for the text/plain part. */
    public static function toText(?string $html): string
    {
        $html = (string) $html;
        // Block ends become line breaks before the tags are stripped.
        $html = preg_replace('#<\s*br\s*/?>#i', "\n", $html) ?? $html;
        $html = preg_replace('#</\s*(p|div|h[1-6]|li|blockquote)\s*>#i', "\n\n", $html) ?? $html;
        $html = preg_replace('#<\s*li[^>]*>#i', '- ', $html) ?? $html;
        // Keep link targets readable: "text (https://…)".
        $html = preg_replace('#<a\s[^>]*href=("|\')(.*?)\1[^>]*>(.*?)</a>#is', '$3 ($2)', $html) ?? $html;

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    private static function walk(DOMNode $node, string $linkColor): void
    {
        // Snapshot the children: unwrapping and removing mutate the live list.
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child->nodeType === XML_COMMENT_NODE) {
                $node->removeChild($child);

                continue;
            }

            if (! $child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);

            if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
                $node->removeChild($child);

                continue;
            }

            // Recurse first so nested content is cleaned before any unwrap
            // moves it up a level.
            self::walk($child, $linkColor);

            if (! array_key_exists($tag, self::ALLOWED)) {
                self::unwrap($child);

                continue;
            }

            $href = $tag === 'a' ? self::safeHref($child->getAttribute('href')) : null;

            // Strip EVERY attribute — style, class, on*, data-*, src — then put
            // back only what we chose.
            while ($child->attributes->length > 0) {
                $child->removeAttribute($child->attributes->item(0)->nodeName);
            }

            if ($tag === 'a') {
                $child->setAttribute('href', $href);
                $child->setAttribute('target', '_blank');
                $child->setAttribute('style', "color:{$linkColor};text-decoration:underline;");
            } elseif (self::ALLOWED[$tag] !== '') {
                $child->setAttribute('style', self::ALLOWED[$tag]);
            }
        }
    }

    /** Replace an element with its own children. */
    private static function unwrap(DOMElement $element): void
    {
        $parent = $element->parentNode;

        while ($element->firstChild) {
            $parent->insertBefore($element->firstChild, $element);
        }

        $parent->removeChild($element);
    }

    /** Only web and mail links survive; anything else becomes inert. */
    public static function safeHref(?string $href): string
    {
        $href = trim((string) $href);

        if ($href === '') {
            return '#';
        }

        // Merge tags are resolved later, per recipient.
        if (preg_match('/^\{\{\s*[a-z_]+\s*\}\}$/', $href)) {
            return $href;
        }

        // A site-relative link ("/en-my/all/") means nothing in an inbox,
        // which has no "current site" to resolve it against. Make it absolute
        // rather than discarding it. "//host" is protocol-relative, not ours.
        if (str_starts_with($href, '/') && ! str_starts_with($href, '//')) {
            return url($href);
        }

        $scheme = strtolower((string) parse_url($href, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https', 'mailto'], true) ? $href : '#';
    }
}

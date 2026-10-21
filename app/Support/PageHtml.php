<?php

namespace App\Support;

use DOMDocument;
use DOMXPath;

/**
 * A whole HTML document pasted into a CMS page ("HTML mode").
 *
 * The document keeps its own <style> blocks, inline styles, classes, fonts and
 * layout — that is the point of pasting it. What it loses is anything that
 * could run: scripts, event-handler attributes, javascript: and data:text/html
 * links, nested documents (srcdoc, object, embed), and the tags that can take
 * over the page (base, meta refresh).
 *
 * The page renders it inside a shadow root (resources/js/components/
 * shadow-html.tsx), which keeps its CSS off the site's header and footer and
 * the site's CSS off it, and which cleans it once more before inserting it.
 */
final class PageHtml
{
    /** Two megabytes is a very large page; anything past it is a mistake. */
    public const MAX = 2_000_000;

    /**
     * The document as stored.
     *
     * Pattern-based rather than parsed and re-written on purpose: PHP's HTML
     * parser lower-cases attribute names, which breaks inline SVG (viewBox)
     * and mangles other HTML5 it doesn't know. The browser does the real
     * parsing, and the renderer cleans again with a proper HTML5 parser.
     */
    public static function clean(string $html): string
    {
        // Structured data is kept: it never runs, and it is often why the HTML
        // was pasted (the pricing page came with its FAQ schema). Each block is
        // set aside, then put back re-encoded from parsed JSON — so what is
        // stored is data and only data, whatever the original script held.
        $jsonLd = [];
        $html = (string) preg_replace_callback(self::JSON_LD, function (array $m) use (&$jsonLd) {
            $data = json_decode(html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5), true);

            if (! is_array($data)) {
                return '';
            }

            $jsonLd[] = $data;

            return '%%JSONLD'.(count($jsonLd) - 1).'%%';
        }, $html);

        $html = self::removeExecutable($html);

        return (string) preg_replace_callback('/%%JSONLD(\d+)%%/', fn (array $m) => isset($jsonLd[(int) $m[1]])
            ? '<script type="application/ld+json">'.self::encodeJsonLd($jsonLd[(int) $m[1]]).'</script>'
            : '', $html);
    }

    /** `<script type="application/ld+json">…</script>`, the one kind of script kept. */
    private const JSON_LD = '#<\s*script\b[^>]*\btype\s*=\s*["\']?application/ld\+json["\']?[^>]*>(.*?)<\s*/\s*script\s*>#is';

    /**
     * The structured data in a pasted page, for SeoManager to put in <head>,
     * where search engines read it.
     *
     * @return list<array<string, mixed>>
     */
    public static function jsonLd(string $html): array
    {
        preg_match_all(self::JSON_LD, $html, $matches);

        $nodes = [];
        foreach ($matches[1] as $json) {
            $data = json_decode(html_entity_decode(trim($json), ENT_QUOTES | ENT_HTML5), true);

            if (! is_array($data)) {
                continue;
            }

            // A @graph is several nodes; a list is several documents.
            foreach (isset($data['@graph']) ? (array) $data['@graph'] : (array_is_list($data) ? $data : [$data]) as $node) {
                if (is_array($node) && isset($node['@type'])) {
                    $nodes[] = $node;
                }
            }
        }

        return $nodes;
    }

    private static function encodeJsonLd(array $data): string
    {
        return str_replace('</', '<\/', (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    private static function removeExecutable(string $html): string
    {
        $html = HtmlSanitizer::clean($html);

        $html = (string) preg_replace([
            // Elements that load or run something of their own.
            '#<\s*(object|applet)\b[^>]*>.*?<\s*/\s*\1\s*>#is',
            '#<\s*(?:embed|base)\b[^>]*>#i',
            // A meta refresh can send every visitor elsewhere.
            '#<\s*meta\b[^>]*http-equiv[^>]*>#i',
        ], '', $html);

        // The attribute rules apply inside tags only, so ordinary text that
        // happens to read "someone = …" is never touched.
        // Quote-aware, so a ">" inside an attribute value cannot end the tag
        // early and leave a handler after it unexamined.
        return (string) preg_replace_callback(
            '#<[a-z](?:[^>"\']|"[^"]*"|\'[^\']*\')*>#i',
            fn (array $tag) => self::cleanTag($tag[0]),
            $html,
        );
    }

    private static function cleanTag(string $tag): string
    {
        $tag = (string) preg_replace([
            // A document inside an attribute, or a form button that posts anywhere.
            '#[\s/](?:srcdoc|formaction)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i',
            // Handlers, including ones written without a space: <img/onerror=…>.
            '#(?<=[\s/"\'])on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i',
        ], '', $tag);

        // Links that would run code: quoted and unquoted.
        $tag = (string) preg_replace(
            '#\b(href|src|action|xlink:href)\s*=\s*(["\'])\s*(?:javascript|vbscript|data:text/html)[^"\']*\2#i',
            '$1=$2#$2',
            $tag,
        );

        return (string) preg_replace(
            '#\b(href|src|action|xlink:href)\s*=\s*(?:javascript|vbscript|data:text/html)[^\s>]*#i',
            '$1="#"',
            $tag,
        );
    }

    /**
     * The readable part of the document: its body's text and links, with no
     * styles, scripts, embeds or attributes beyond href/src/alt/title.
     *
     * This is what crawlers read before any JavaScript runs, and what the meta
     * description falls back to. It is written into the main document rather
     * than the shadow root, so it is rebuilt from a parsed tree instead of
     * trusting the pasted markup.
     */
    public static function content(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        // The declaration makes libxml read the bytes as UTF-8.
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($dom);

        $drop = '//script|//style|//link|//noscript|//template|//iframe|//object|//embed|//svg|//form|//base|//meta|//button|//input|//select|//textarea';
        foreach (iterator_to_array($xpath->query($drop)) as $node) {
            $node->parentNode?->removeChild($node);
        }

        foreach (iterator_to_array($xpath->query('//@*')) as $attribute) {
            $name = strtolower($attribute->nodeName);
            $isLink = in_array($name, ['href', 'src'], true);

            if (! in_array($name, ['href', 'src', 'alt', 'title'], true)
                || ($isLink && preg_match('/^\s*(javascript|vbscript|data):/i', (string) $attribute->nodeValue))) {
                $attribute->ownerElement?->removeAttribute($attribute->nodeName);
            }
        }

        $body = $dom->getElementsByTagName('body')->item(0);

        if (! $body) {
            return '';
        }

        $out = '';
        foreach ($body->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }

        return trim($out);
    }
}

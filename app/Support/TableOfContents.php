<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Builds a table of contents for an authored post, the way the WordPress TOC
 * plugins do: every H2/H3 gets a stable anchor id, the headings are collected
 * into a nested list, and any [data-toc] block the author dropped into the body
 * is swapped for the rendered list.
 *
 * Done server-side so the anchors and the list are in the HTML the crawler
 * receives, not injected later by JavaScript. Deliberately regex-based rather
 * than DOM-based: the input is our own editor's markup, and the host's PHP
 * build can't be assumed to carry every extension (see the fileinfo note in
 * MediaController).
 */
class TableOfContents
{
    /** Headings that make it into the contents. H4+ is detail, not structure. */
    private const LEVELS = [2, 3];

    /**
     * @return array{html: string, items: array<int, array{id: string, text: string, level: int}>}
     */
    public static function build(?string $html): array
    {
        if (! is_string($html) || trim($html) === '') {
            return ['html' => (string) $html, 'items' => []];
        }

        $items = [];
        $used = [];
        $levels = implode('', self::LEVELS);

        $html = preg_replace_callback(
            '#<h(['.$levels.'])\b([^>]*)>(.*?)</h\1>#is',
            function (array $m) use (&$items, &$used): string {
                [$all, $level, $attrs, $inner] = $m;

                $text = trim(html_entity_decode(strip_tags($inner), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($text === '') {
                    return $all; // an empty heading anchors nothing
                }

                // Respect an id the author already set, otherwise derive one.
                $id = preg_match('#\bid\s*=\s*"([^"]+)"#i', $attrs, $found) ? $found[1] : '';
                if ($id === '') {
                    $id = self::uniqueId($text, $used);
                    $attrs .= ' id="'.e($id).'"';
                } else {
                    $used[$id] = true;
                }

                $items[] = ['id' => $id, 'text' => $text, 'level' => (int) $level];

                return '<h'.$level.$attrs.'>'.$inner.'</h'.$level.'>';
            },
            $html
        ) ?? $html;

        return ['html' => self::replacePlaceholder($html, $items), 'items' => $items];
    }

    /** A URL-safe, unique anchor for a heading. */
    private static function uniqueId(string $text, array &$used): string
    {
        $base = Str::slug(Str::limit($text, 60, '')) ?: 'section';
        $id = $base;
        $i = 2;
        while (isset($used[$id])) {
            $id = $base.'-'.$i++;
        }
        $used[$id] = true;

        return $id;
    }

    /**
     * Swap the author's inline [data-toc] block for the rendered contents. The
     * placeholder is a single flat <div> written by the editor's "Table of
     * contents" button, so a non-greedy match is safe; with no headings to list
     * it's removed rather than left as an empty box.
     */
    private static function replacePlaceholder(string $html, array $items): string
    {
        if (! str_contains($html, 'data-toc')) {
            return $html;
        }

        return preg_replace(
            '#<div\b[^>]*\bdata-toc\b[^>]*>.*?</div>#is',
            $items ? self::render($items) : '',
            $html
        ) ?? $html;
    }

    /** The contents as a semantic, indented nav list. */
    public static function render(array $items): string
    {
        if (! $items) {
            return '';
        }

        $out = '<nav class="post-toc" aria-label="Table of contents">'
             .'<p class="post-toc-title">Table of Contents</p><ol>';

        foreach ($items as $item) {
            $out .= '<li class="post-toc-l'.$item['level'].'">'
                 .'<a href="#'.e($item['id']).'">'.e($item['text']).'</a></li>';
        }

        return $out.'</ol></nav>';
    }
}

<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Server-side SEO for a page. Populated in controllers, rendered by Laravel
 * directly into the HTML <head> (see app.blade.php) — so search engines and
 * social scrapers get the full title, meta, Open Graph, Twitter Card, canonical,
 * robots directives and JSON-LD structured data on the first byte, with NO
 * JavaScript/Node/SSR required.
 *
 * Request-scoped: one instance per request (bound in AppServiceProvider).
 */
class SeoManager
{
    protected ?string $title = null;
    protected bool $appendSiteName = true;
    protected ?string $description = null;
    protected ?string $canonical = null;
    protected bool $index = true;
    protected bool $follow = true;
    protected string $ogType = 'website';
    protected ?string $ogTitle = null;
    protected ?string $image = null;
    protected ?int $imageW = null;
    protected ?int $imageH = null;
    protected ?string $imageAlt = null;
    protected ?string $keywords = null;

    /** article:* metadata (published_time, modified_time, section, author, tags[]). */
    protected array $article = [];

    /** Page-specific JSON-LD nodes, merged into the global @graph. */
    protected array $graph = [];

    /** Extra raw <meta> tags: ['name'|'property' => ..., 'content' => ...]. */
    protected array $extra = [];

    /** Server-rendered content HTML for crawlers (there's no Node/SSR in prod). */
    protected ?string $crawlableHtml = null;

    // ---- fluent setters ----------------------------------------------------

    /**
     * Provide the page's main content as HTML so it's present in the server
     * response for crawlers/agents that don't run JavaScript (emitted in a
     * <noscript> block; the React app renders the same content for real users).
     */
    public function crawlable(?string $html): static
    {
        $this->crawlableHtml = $html ? trim($html) : null;

        return $this;
    }

    public function crawlableHtml(): ?string
    {
        return $this->crawlableHtml;
    }

    public function title(?string $title, bool $appendSiteName = true): static
    {
        $this->title = $title ? trim($title) : null;
        $this->appendSiteName = $appendSiteName;

        return $this;
    }

    public function description(?string $description): static
    {
        $this->description = $description ? trim(preg_replace('/\s+/', ' ', $description)) : null;

        return $this;
    }

    public function canonical(?string $url): static
    {
        $this->canonical = $url ? $this->absolute($url) : null;

        return $this;
    }

    public function robots(bool $index, bool $follow = true): static
    {
        $this->index = $index;
        $this->follow = $follow;

        return $this;
    }

    public function noindex(): static
    {
        $this->index = false;

        return $this;
    }

    public function type(string $ogType): static
    {
        $this->ogType = $ogType;

        return $this;
    }

    public function ogTitle(?string $title): static
    {
        $this->ogTitle = $title;

        return $this;
    }

    public function image(?string $url, ?int $width = null, ?int $height = null, ?string $alt = null): static
    {
        if ($url) {
            $this->image = $this->absolute($url);
            $this->imageW = $width;
            $this->imageH = $height;
            $this->imageAlt = $alt;
        }

        return $this;
    }

    public function keywords(?string $keywords): static
    {
        $this->keywords = $keywords ?: null;

        return $this;
    }

    /** Mark this as an article and attach article:* metadata. */
    public function article(array $data): static
    {
        $this->article = $data;
        $this->ogType = 'article';

        return $this;
    }

    /** Add a JSON-LD node (its own @context, if any, is dropped — the graph owns it). */
    public function schema(array $node): static
    {
        unset($node['@context']);
        $this->graph[] = $node;

        return $this;
    }

    public function meta(string $key, string $content, bool $property = false): static
    {
        $this->extra[] = [$property ? 'property' : 'name' => $key, 'content' => $content];

        return $this;
    }

    /** Add a BreadcrumbList. $items = [['name' => 'Home', 'url' => '/'], ...]. */
    public function breadcrumb(array $items): static
    {
        $elements = [];
        foreach (array_values($items) as $i => $item) {
            $elements[] = $this->prune([
                '@type' => 'ListItem',
                'position' => $i + 1,
                'name' => $item['name'] ?? null,
                'item' => isset($item['url']) ? $this->absolute($item['url']) : null,
            ]);
        }

        return $this->schema(['@type' => 'BreadcrumbList', 'itemListElement' => $elements]);
    }

    /**
     * Convenience: apply a content model's per-entry SeoMeta overrides
     * (Yoast/Rank-Math-style) on top of sensible fallbacks.
     */
    public function fromSeoMeta(?\App\Models\SeoMeta $seo, string $fallbackTitle, ?string $fallbackDescription, ?string $canonical, ?string $fallbackImage = null): static
    {
        $this->title($seo?->seo_title ?: $fallbackTitle);
        $this->description($seo?->meta_description ?: $fallbackDescription);
        $this->canonical($seo?->canonical_url ?: $canonical);
        $this->image($seo?->og_image ?: $fallbackImage);
        $this->ogTitle($seo?->og_title ?: null);
        if ($seo && $seo->og_description) {
            // og:description override handled via description fallback chain below
            $this->extra[] = ['property' => 'og:description', 'content' => $seo->og_description];
        }
        if ($seo) {
            $this->robots((bool) $seo->robots_index, (bool) $seo->robots_follow);
        }

        return $this;
    }

    // ---- rendering ---------------------------------------------------------

    public function render(): string
    {
        $site = config('seo.site_name', 'DropRSVP');
        $sep = config('seo.title_separator', '·');

        $displayTitle = $this->title ?: $site;
        if ($this->title && $this->appendSiteName && ! Str::contains($this->title, $site)) {
            $displayTitle = "{$this->title} {$sep} {$site}";
        }

        $canonical = $this->trailingSlash($this->canonical ?: url()->current());
        $image = $this->image ?: ($this->absolute(config('seo.default_image')) ?: null);
        $ogTitle = $this->ogTitle ?: ($this->title ?: $site);

        $lines = [];
        $lines[] = '<title>'.e($displayTitle).'</title>';

        if ($this->description) {
            $lines[] = $this->tag('name', 'description', $this->description);
        }
        if ($this->keywords) {
            $lines[] = $this->tag('name', 'keywords', $this->keywords);
        }
        $lines[] = '<link rel="canonical" href="'.e($canonical).'">';
        $lines[] = $this->tag('name', 'robots', $this->robotsContent());

        // ---- Open Graph ----
        $lines[] = $this->tag('property', 'og:type', $this->ogType);
        $lines[] = $this->tag('property', 'og:site_name', $site);
        $lines[] = $this->tag('property', 'og:locale', config('seo.locale', 'en_US'));
        $lines[] = $this->tag('property', 'og:title', $ogTitle);
        if ($this->description) {
            $lines[] = $this->tag('property', 'og:description', $this->description);
        }
        $lines[] = $this->tag('property', 'og:url', $canonical);
        if ($image) {
            $lines[] = $this->tag('property', 'og:image', $image);
            if ($this->imageW) {
                $lines[] = $this->tag('property', 'og:image:width', (string) $this->imageW);
            }
            if ($this->imageH) {
                $lines[] = $this->tag('property', 'og:image:height', (string) $this->imageH);
            }
            if ($this->imageAlt) {
                $lines[] = $this->tag('property', 'og:image:alt', $this->imageAlt);
            }
        }

        // ---- article:* ----
        if ($this->ogType === 'article' && $this->article) {
            foreach (['published_time', 'modified_time', 'section', 'author'] as $k) {
                if (! empty($this->article[$k])) {
                    $lines[] = $this->tag('property', "article:{$k}", (string) $this->article[$k]);
                }
            }
            foreach ((array) ($this->article['tags'] ?? []) as $tag) {
                $lines[] = $this->tag('property', 'article:tag', (string) $tag);
            }
        }

        // ---- Twitter ----
        $lines[] = $this->tag('name', 'twitter:card', $image ? 'summary_large_image' : 'summary');
        if ($handle = config('seo.twitter')) {
            $lines[] = $this->tag('name', 'twitter:site', $handle);
        }
        $lines[] = $this->tag('name', 'twitter:title', $ogTitle);
        if ($this->description) {
            $lines[] = $this->tag('name', 'twitter:description', $this->description);
        }
        if ($image) {
            $lines[] = $this->tag('name', 'twitter:image', $image);
        }

        // ---- extra raw meta (may include per-entry og:description override) ----
        foreach ($this->extra as $m) {
            $key = isset($m['property']) ? 'property' : 'name';
            $lines[] = $this->tag($key, $m[$key], $m['content']);
        }

        // ---- JSON-LD @graph ----
        $lines[] = $this->jsonLd($canonical);

        return implode("\n        ", $lines);
    }

    // ---- internals ---------------------------------------------------------

    protected function robotsContent(): string
    {
        $parts = [$this->index ? 'index' : 'noindex', $this->follow ? 'follow' : 'nofollow'];
        if ($this->index) {
            // Modern discovery directives (what Rank Math emits) — richer snippets.
            $parts = array_merge($parts, ['max-snippet:-1', 'max-image-preview:large', 'max-video-preview:-1']);
        }

        return implode(', ', $parts);
    }

    protected function tag(string $attr, string $key, string $content): string
    {
        return '<meta '.$attr.'="'.e($key).'" content="'.e($content).'">';
    }

    protected function jsonLd(string $canonical): string
    {
        $site = config('seo.site_name', 'DropRSVP');
        $lang = str_replace('_', '-', (string) config('seo.locale', 'en_US'));
        $orgId = url('/#organization');
        $siteId = url('/#website');

        $organization = $this->prune([
            '@type' => 'Organization',
            '@id' => $orgId,
            'name' => config('seo.organization.name', $site),
            'url' => url('/'),
            'logo' => ($logo = config('seo.organization.logo'))
                ? ['@type' => 'ImageObject', 'url' => $this->absolute($logo)]
                : null,
            'sameAs' => config('seo.organization.same_as') ?: null,
        ]);

        $website = [
            '@type' => 'WebSite',
            '@id' => $siteId,
            'url' => url('/'),
            'name' => $site,
            'publisher' => ['@id' => $orgId],
            'inLanguage' => $lang,
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => ['@type' => 'EntryPoint', 'urlTemplate' => url('/events').'?q={search_term_string}'],
                'query-input' => 'required name=search_term_string',
            ],
        ];

        $graph = array_merge([$organization, $website], array_map(fn ($n) => $this->prune($n), $this->graph));

        $json = json_encode(
            ['@context' => 'https://schema.org', '@graph' => array_values($graph)],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        // Guard against premature </script> in any string field.
        $json = str_replace('</', '<\/', $json);

        return '<script type="application/ld+json">'.$json.'</script>';
    }

    /** Recursively drop null / empty-array values so the JSON-LD stays clean. */
    protected function prune(array $node): array
    {
        foreach ($node as $k => $v) {
            if (is_array($v)) {
                $v = $this->prune($v);
                $node[$k] = $v;
            }
            if ($v === null || $v === '' || $v === []) {
                unset($node[$k]);
            }
        }

        return $node;
    }

    protected function absolute(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return Str::startsWith($path, ['http://', 'https://']) ? $path : url($path);
    }

    /**
     * Enforce a trailing slash on the URL path so the canonical/OG URL matches
     * the .htaccess-enforced form (https://www.droprsvp.com/en-my/). Leaves the
     * query/fragment intact and skips file-like paths (a dot in the last segment).
     */
    protected function trailingSlash(string $url): string
    {
        $parts = parse_url($url);
        $path = $parts['path'] ?? '/';
        $last = substr($path, (int) strrpos($path, '/') + 1);

        if ($path !== '' && ! str_ends_with($path, '/') && ! str_contains($last, '.')) {
            $path .= '/';
        }

        $out = ($parts['scheme'] ?? '') !== '' ? $parts['scheme'].'://' : '';
        $out .= $parts['host'] ?? '';
        $out .= isset($parts['port']) ? ':'.$parts['port'] : '';
        $out .= $path;
        $out .= isset($parts['query']) ? '?'.$parts['query'] : '';
        $out .= isset($parts['fragment']) ? '#'.$parts['fragment'] : '';

        return $out;
    }
}

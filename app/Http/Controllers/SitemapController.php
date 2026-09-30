<?php

namespace App\Http\Controllers;

use App\Models\CmsPage;
use App\Models\CmsPost;
use App\Models\Event;
use App\Models\EventCategory;
use App\Models\HelpArticle;
use App\Models\User;
use App\Support\Cities;
use App\Support\Url;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * XML sitemaps, split by content type behind an index — the shape Yoast
 * produces, and the shape Search Console reports against.
 *
 * One flat file listing everything still works, but it makes two things hard.
 * You cannot see at a glance whether the problem is "events aren't indexed" or
 * "blog posts aren't", because coverage is reported for the file as a whole.
 * And a single <lastmod> for a file containing every URL on the site tells a
 * crawler nothing about what actually changed.
 *
 * So: /sitemap.xml is an index, and each type gets its own file whose lastmod
 * is the newest record in it. Nothing is cached — these are cheap queries, and
 * a sitemap that lags behind is exactly the complaint this replaces.
 */
class SitemapController extends Controller
{
    /** The sub-sitemaps, in the order they appear in the index. */
    private const SECTIONS = ['page', 'event', 'organizer', 'post', 'category', 'city', 'help'];

    /** The index: one entry per sub-sitemap that currently has URLs. */
    public function index(): Response
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

        foreach (self::SECTIONS as $section) {
            $urls = $this->urlsFor($section);

            // An empty sub-sitemap is a warning in Search Console, so a section
            // with nothing in it simply is not listed.
            if ($urls === []) {
                continue;
            }

            $xml .= '  <sitemap><loc>'.htmlspecialchars(url("/{$section}-sitemap.xml"), ENT_XML1).'</loc>';

            if ($lastmod = $this->newest($urls)) {
                $xml .= '<lastmod>'.$lastmod.'</lastmod>';
            }

            $xml .= "</sitemap>\n";
        }

        $xml .= '</sitemapindex>';

        return $this->xml($xml);
    }

    /** One sub-sitemap. */
    public function section(string $section): Response
    {
        abort_unless(in_array($section, self::SECTIONS, true), 404);

        $urls = $this->urlsFor($section);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">'."\n";

        foreach ($urls as $u) {
            $xml .= '  <url><loc>'.htmlspecialchars(Url::slash($u['loc']), ENT_XML1).'</loc>';

            if (! empty($u['lastmod'])) {
                $xml .= '<lastmod>'.$u['lastmod'].'</lastmod>';
            }
            if (! empty($u['image'])) {
                $xml .= '<image:image><image:loc>'.htmlspecialchars($u['image'], ENT_XML1).'</image:loc></image:image>';
            }

            $xml .= "</url>\n";
        }

        $xml .= '</urlset>';

        return $this->xml($xml);
    }

    /**
     * @return list<array{loc:string,lastmod:?string,image:?string}>
     */
    private function urlsFor(string $section): array
    {
        return match ($section) {
            'page' => $this->pages(),
            'event' => $this->events(),
            'organizer' => $this->organizers(),
            'post' => $this->posts(),
            'category' => $this->categories(),
            'city' => $this->cities(),
            'help' => $this->help(),
            default => [],
        };
    }

    /** The fixed landing pages, plus anything built in the page builder. */
    private function pages(): array
    {
        $urls = [
            $this->url(Url::to()),
            $this->url(Url::to(Cities::ANY)),
            $this->url(Url::to('blog')),
            $this->url(Url::to('help')),
            $this->url(Url::to('contact')),
        ];

        foreach (CmsPage::published()->get(['slug', 'updated_at']) as $p) {
            $urls[] = $this->url(Url::to($p->slug), $p->updated_at);
        }

        return $urls;
    }

    private function events(): array
    {
        return Event::published()
            ->orderByDesc('updated_at')
            ->get(['slug', 'cover_image', 'banner_image', 'updated_at'])
            ->map(fn ($e) => $this->url(Url::to('e', $e->slug), $e->updated_at, $e->cover_image ?: $e->banner_image))
            ->all();
    }

    /**
     * Organizer profiles.
     *
     * Requires a PUBLISHED event, not merely any event. An organizer whose only
     * events are drafts has a profile page with nothing on it, and submitting
     * empty pages is how a site earns a "crawled — currently not indexed" pile
     * in Search Console.
     */
    private function organizers(): array
    {
        return User::whereNotNull('slug')
            ->whereHas('events', fn ($q) => $q->published())
            ->orderByDesc('updated_at')
            ->get(['slug', 'updated_at'])
            ->map(fn ($o) => $this->url(Url::to('o', $o->slug), $o->updated_at))
            ->all();
    }

    private function posts(): array
    {
        return CmsPost::published()
            ->orderByDesc('updated_at')
            ->get(['slug', 'cover_image', 'updated_at'])
            ->map(fn ($p) => $this->url(Url::to('blog', $p->slug), $p->updated_at, $p->cover_image))
            ->all();
    }

    private function categories(): array
    {
        return EventCategory::orderBy('name')->get(['slug', 'updated_at'])
            ->map(fn ($c) => $this->url(Url::to(Cities::ANY, $c->slug), $c->updated_at))
            ->all();
    }

    /** Only cities that actually have a published event to show. */
    private function cities(): array
    {
        return Event::published()->whereNotNull('city')->distinct()->pluck('city')
            ->map(fn ($name) => $this->url(Url::to(Cities::slugForName($name))))
            ->all();
    }

    private function help(): array
    {
        return HelpArticle::where('status', 'published')
            ->orderByDesc('updated_at')
            ->get(['slug', 'updated_at'])
            ->map(fn ($a) => $this->url(Url::to('help', $a->slug), $a->updated_at))
            ->all();
    }

    /** @return array{loc:string,lastmod:?string,image:?string} */
    private function url(string $loc, ?\DateTimeInterface $lastmod = null, ?string $image = null): array
    {
        return [
            'loc' => $loc,
            'lastmod' => $lastmod?->format('Y-m-d'),
            'image' => $this->abs($image),
        ];
    }

    /** The newest lastmod in a set, for the index entry. */
    private function newest(array $urls): ?string
    {
        $dates = array_filter(array_column($urls, 'lastmod'));

        return $dates ? max($dates) : null;
    }

    private function xml(string $body): Response
    {
        return response($body, 200)->header('Content-Type', 'application/xml');
    }

    private function abs(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return Str::startsWith($path, ['http://', 'https://']) ? $path : url($path);
    }
}

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

class SitemapController extends Controller
{
    /** XML sitemap (with image entries) of every public, indexable URL. */
    public function index(): Response
    {
        $urls = [
            ['loc' => Url::to(), 'lastmod' => null, 'image' => null],               // home
            ['loc' => Url::to(Cities::ANY), 'lastmod' => null, 'image' => null],    // browse all
            ['loc' => Url::to('blog'), 'lastmod' => null, 'image' => null],
            ['loc' => Url::to('help'), 'lastmod' => null, 'image' => null],
            ['loc' => Url::to('contact'), 'lastmod' => null, 'image' => null],
        ];

        // City + category discovery landing pages (only cities that actually have events).
        $cityNames = Event::published()->whereNotNull('city')->distinct()->pluck('city');
        $categories = EventCategory::orderBy('name')->get(['slug']);
        foreach ($cityNames as $name) {
            $urls[] = ['loc' => Url::to(Cities::slugForName($name)), 'lastmod' => null, 'image' => null];
        }
        foreach ($categories as $cat) {
            $urls[] = ['loc' => Url::to(Cities::ANY, $cat->slug), 'lastmod' => null, 'image' => null];
        }

        foreach (Event::published()->get(['slug', 'cover_image', 'updated_at']) as $e) {
            $urls[] = ['loc' => Url::to('e', $e->slug), 'lastmod' => $e->updated_at?->toDateString(), 'image' => $this->abs($e->cover_image)];
        }
        foreach (CmsPage::published()->get(['slug', 'updated_at']) as $p) {
            $urls[] = ['loc' => Url::to($p->slug), 'lastmod' => $p->updated_at?->toDateString(), 'image' => null];
        }
        foreach (CmsPost::published()->get(['slug', 'cover_image', 'updated_at']) as $p) {
            $urls[] = ['loc' => Url::to('blog', $p->slug), 'lastmod' => $p->updated_at?->toDateString(), 'image' => $this->abs($p->cover_image)];
        }
        foreach (HelpArticle::where('status', 'published')->get(['slug', 'updated_at']) as $a) {
            $urls[] = ['loc' => Url::to('help', $a->slug), 'lastmod' => $a->updated_at?->toDateString(), 'image' => null];
        }
        // Organizer profiles that actually have something to show.
        foreach (User::has('events')->whereNotNull('slug')->get(['slug', 'updated_at']) as $o) {
            $urls[] = ['loc' => Url::to('o', $o->slug), 'lastmod' => $o->updated_at?->toDateString(), 'image' => null];
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">'."\n";
        foreach ($urls as $u) {
            $xml .= '  <url><loc>'.htmlspecialchars(Url::slash($u['loc']), ENT_XML1).'</loc>';
            if ($u['lastmod']) {
                $xml .= '<lastmod>'.$u['lastmod'].'</lastmod>';
            }
            if ($u['image']) {
                $xml .= '<image:image><image:loc>'.htmlspecialchars($u['image'], ENT_XML1).'</image:loc></image:image>';
            }
            $xml .= "</url>\n";
        }
        $xml .= '</urlset>';

        return response($xml, 200)->header('Content-Type', 'application/xml');
    }

    private function abs(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return Str::startsWith($path, ['http://', 'https://']) ? $path : url($path);
    }
}

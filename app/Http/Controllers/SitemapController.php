<?php

namespace App\Http\Controllers;

use App\Models\CmsPage;
use App\Models\CmsPost;
use App\Models\Event;
use App\Models\EventCategory;
use App\Support\Cities;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class SitemapController extends Controller
{
    /** XML sitemap (with image entries) of every public, indexable URL. */
    public function index(): Response
    {
        $locale = 'en-my';
        $urls = [
            ['loc' => url("/{$locale}"), 'lastmod' => null, 'image' => null],          // home
            ['loc' => url("/{$locale}/".Cities::ANY), 'lastmod' => null, 'image' => null], // browse all
            ['loc' => url('/blog'), 'lastmod' => null, 'image' => null],
        ];

        // City + category discovery landing pages (only cities that actually have events).
        $cityNames = Event::published()->whereNotNull('city')->distinct()->pluck('city');
        $categories = EventCategory::orderBy('name')->get(['slug']);
        foreach ($cityNames as $name) {
            $urls[] = ['loc' => url("/{$locale}/".Cities::slugForName($name)), 'lastmod' => null, 'image' => null];
        }
        foreach ($categories as $cat) {
            $urls[] = ['loc' => url("/{$locale}/".Cities::ANY.'/'.$cat->slug), 'lastmod' => null, 'image' => null];
        }

        foreach (Event::published()->get(['slug', 'cover_image', 'updated_at']) as $e) {
            $urls[] = ['loc' => url('/en-my/e/'.$e->slug), 'lastmod' => $e->updated_at?->toDateString(), 'image' => $this->abs($e->cover_image)];
        }
        foreach (CmsPage::published()->get(['slug', 'updated_at']) as $p) {
            $urls[] = ['loc' => url('/'.$p->slug), 'lastmod' => $p->updated_at?->toDateString(), 'image' => null];
        }
        foreach (CmsPost::published()->get(['slug', 'cover_image', 'updated_at']) as $p) {
            $urls[] = ['loc' => url('/blog/'.$p->slug), 'lastmod' => $p->updated_at?->toDateString(), 'image' => $this->abs($p->cover_image)];
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">'."\n";
        foreach ($urls as $u) {
            $xml .= '  <url><loc>'.htmlspecialchars($u['loc'], ENT_XML1).'</loc>';
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

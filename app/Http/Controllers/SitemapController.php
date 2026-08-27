<?php

namespace App\Http\Controllers;

use App\Models\CmsPage;
use App\Models\CmsPost;
use App\Models\Event;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    /** XML sitemap of every public, indexable URL. */
    public function index(): Response
    {
        $urls = [
            ['loc' => url('/'), 'lastmod' => null],
            ['loc' => url('/blog'), 'lastmod' => null],
        ];

        foreach (Event::published()->get(['slug', 'updated_at']) as $e) {
            $urls[] = ['loc' => url('/e/'.$e->slug), 'lastmod' => $e->updated_at?->toDateString()];
        }
        foreach (CmsPage::published()->get(['slug', 'updated_at']) as $p) {
            $urls[] = ['loc' => url('/'.$p->slug), 'lastmod' => $p->updated_at?->toDateString()];
        }
        foreach (CmsPost::published()->get(['slug', 'updated_at']) as $p) {
            $urls[] = ['loc' => url('/blog/'.$p->slug), 'lastmod' => $p->updated_at?->toDateString()];
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($urls as $u) {
            $xml .= '  <url><loc>'.htmlspecialchars($u['loc'], ENT_XML1).'</loc>';
            if ($u['lastmod']) {
                $xml .= '<lastmod>'.$u['lastmod'].'</lastmod>';
            }
            $xml .= "</url>\n";
        }
        $xml .= '</urlset>';

        return response($xml, 200)->header('Content-Type', 'application/xml');
    }
}

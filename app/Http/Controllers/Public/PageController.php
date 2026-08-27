<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\CmsPage;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

class PageController extends Controller
{
    /** Render a published CMS page at its root slug (fallback route). Server-rendered. */
    public function show(Request $request)
    {
        $slug = trim($request->path(), '/');
        $page = CmsPage::published()->with('seo')->where('slug', $slug)->first();
        abort_if(! $page, 404);

        $description = $page->seo?->meta_description ?: Str::limit(trim(strip_tags((string) $page->body)), 155);
        $canonical = $page->seo?->canonical_url ?: url('/'.$page->slug);

        return Inertia::render('public/page', [
            'page' => [
                'title' => $page->title,
                'body' => $page->body,
            ],
            'seo' => [
                'title' => $page->seo?->seo_title ?: $page->title,
                'description' => $description,
                'canonical' => $canonical,
                'og_image' => $page->seo?->og_image,
                'robots' => $page->seo?->robotsDirective() ?? 'index, follow',
            ],
            'schema' => array_filter([
                '@context' => 'https://schema.org',
                '@type' => 'WebPage',
                'name' => $page->seo?->seo_title ?: $page->title,
                'description' => $description,
                'url' => $canonical,
            ]),
        ]);
    }
}

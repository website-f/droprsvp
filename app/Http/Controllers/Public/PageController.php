<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\CmsPage;
use App\Support\SeoManager;
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

        $seo = $page->seo;
        $description = $seo?->meta_description ?: Str::limit(trim(strip_tags((string) $page->body)), 155);
        $canonical = $seo?->canonical_url ?: url('/'.$page->slug);

        app(SeoManager::class)
            ->title($seo?->seo_title ?: $page->title)
            ->description($description)
            ->keywords($seo?->meta_keywords)
            ->canonical($canonical)
            ->image($seo?->og_image)
            ->robots((bool) ($seo->robots_index ?? true), (bool) ($seo->robots_follow ?? true))
            ->schema([
                '@type' => 'WebPage',
                'name' => $seo?->seo_title ?: $page->title,
                'description' => $description,
                'url' => $canonical,
                'isPartOf' => ['@id' => url('/#website')],
            ])
            ->breadcrumb([
                ['name' => 'Home', 'url' => url('/')],
                ['name' => $seo?->breadcrumb_title ?: $page->title, 'url' => $canonical],
            ]);

        return Inertia::render('public/page', [
            'page' => [
                'title' => $page->title,
                'body' => $page->body,
                'layout' => $page->layout,
                'puck' => $page->puck_data,
            ],
            'seo' => ['title' => $seo?->seo_title ?: $page->title],
        ]);
    }
}

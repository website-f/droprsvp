<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\CmsPage;
use App\Support\PageHtml;
use App\Support\PostCards;
use App\Support\SeoManager;
use App\Support\Url;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

class PageController extends Controller
{
    /**
     * A published CMS page used to live at its bare root slug (/terms). It now
     * has a locale-prefixed canonical URL, so the old path permanently redirects
     * there; anything that was never a page still 404s.
     */
    public function legacyRedirect(Request $request)
    {
        $slug = trim($request->path(), '/');
        abort_unless($slug !== '' && CmsPage::published()->where('slug', $slug)->exists(), 404);

        return redirect(Url::to($slug), 301);
    }

    /**
     * Render a published CMS page. Reached from DiscoverController, which owns
     * the single-segment space under /en-my/ and falls through to here when the
     * segment isn't a city or a category. Server-rendered for SEO.
     */
    public function show(Request $request, string $slug)
    {
        $page = CmsPage::published()->with('seo')->where('slug', $slug)->first();
        abort_if(! $page, 404);

        $seo = $page->seo;
        $description = $seo?->meta_description ?: Str::limit(trim(strip_tags((string) $page->body)), 155);
        $canonical = $seo?->canonical_url ?: Url::to($page->slug);

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
                ['name' => 'Home', 'url' => Url::to()],
                ['name' => $seo?->breadcrumb_title ?: $page->title, 'url' => $canonical],
            ])
            // The page's own text. These are the legal pages (terms, privacy) —
            // pure prose, and they were reaching crawlers as meta tags over an
            // empty body. A Puck-built page has no $body, so there is nothing to
            // emit for one and the heading alone is served.
            ->crawlable('<article><h1>'.e($page->title).'</h1>'.((string) $page->body).'</article>');

        // Structured data that came with pasted HTML (an FAQ, say) goes in
        // <head> with the rest of the page's JSON-LD.
        if ($page->usesHtml()) {
            app(SeoManager::class)->schemas(PageHtml::jsonLd((string) $page->html));
        }

        return Inertia::render('public/page', [
            'page' => [
                'title' => $page->title,
                'body' => $page->body,
                'layout' => $page->layout,
                'puck' => $page->puck_data,
                // Pasted HTML, written into the page (components/html-content).
                'editor' => $page->editor,
                'html' => $page->usesHtml() ? $page->html : null,
                'html_layout' => $page->html_layout,
                // Last saved by a superadmin: its scripts run in the page itself.
                'html_trusted' => $page->html_trusted,
                'posts' => PostCards::recent(),
            ],
            'seo' => ['title' => $seo?->seo_title ?: $page->title],
        ]);
    }
}

<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\HelpArticle;
use App\Support\Regions;
use App\Support\SeoManager;
use App\Support\Url;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Inertia;

class HelpController extends Controller
{
    /** Help center home — searchable, grouped by category (server-rendered). */
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        // What this region sees — each article's version for it — then searched.
        $needle = mb_strtolower($q);
        $articles = $this->shown()
            ->filter(fn (HelpArticle $a) => $q === '' || str_contains(mb_strtolower($a->title.' '.$a->excerpt.' '.strip_tags((string) $a->body)), $needle))
            ->sortBy([['category', 'asc'], ['sort', 'asc'], ['title', 'asc']])
            ->values();

        $categories = $articles->groupBy('category')->map(fn ($items, $name) => [
            'name' => $name,
            'articles' => $items->map(fn ($a) => ['title' => $a->title, 'slug' => $a->slug, 'excerpt' => $a->excerpt])->values(),
        ])->values();

        app(SeoManager::class)
            ->title($q !== '' ? "Help — “{$q}”" : 'Help center')
            ->description('Answers and guides for buying tickets, organizing events and managing your DropRSVP account.')
            ->canonical(Url::global('help'))
            ->breadcrumb([['name' => 'Home', 'url' => Url::to()], ['name' => 'Help center', 'url' => Url::to('help')]])
            // The article list as text. show() already did this per article, but
            // the index — the page that links to all of them — served an empty
            // body, so a non-JS crawler found no path to any of them.
            ->crawlable($this->crawlableIndex($categories));
        if ($q !== '') {
            app(SeoManager::class)->noindex();
        }

        return Inertia::render('public/help/index', [
            'categories' => $categories,
            'filters' => ['q' => $q],
        ]);
    }

    /**
     * The help articles a visitor in this region gets: for each main article,
     * the version written for their region, else the article itself (unless
     * it is only for other regions). `slug` is always the main one's — the
     * address is shared.
     */
    private function shown()
    {
        $region = Regions::current()['code'];

        return HelpArticle::published()->main()
            ->with(['versions' => fn ($q) => $q->published()])
            ->get()
            ->map(function (HelpArticle $main) use ($region) {
                $shown = $main->versions->first(fn (HelpArticle $v) => in_array($region, (array) $v->regions, true))
                    ?? ($main->isShownIn($region) ? $main : null);

                return $shown?->setAttribute('slug', $main->slug);
            })
            ->filter()
            ->values();
    }

    /** A single help article — the version of it for the region being browsed. */
    public function show(HelpArticle $article)
    {
        $main = $article;
        abort_unless($main->status === 'published' && $main->parent_id === null, 404);
        $article = $main->versionFor();
        abort_unless($article, 404);

        $description = $article->excerpt ?: Str::limit(trim(strip_tags((string) $article->body)), 155);
        // Shared by every region: one canonical in the default region. A
        // regional version, or an article for some regions only: here.
        $regional = $article->isNot($main) || ! empty($main->regions);
        $canonical = $regional ? Url::to('help', $main->slug) : Url::global('help', $main->slug);

        app(SeoManager::class)
            ->title($article->title)
            ->description($description)
            ->canonical($canonical)
            ->schema([
                '@type' => 'FAQPage',
                'mainEntity' => [[
                    '@type' => 'Question',
                    'name' => $article->title,
                    'acceptedAnswer' => ['@type' => 'Answer', 'text' => strip_tags((string) $article->body)],
                ]],
            ])
            ->breadcrumb([
                ['name' => 'Home', 'url' => Url::to()],
                ['name' => 'Help center', 'url' => Url::to('help')],
                ['name' => $article->title, 'url' => $canonical],
            ])
            // Server-render the article so non-JS crawlers index the real content.
            ->crawlable('<article><h1>'.e($article->title).'</h1>'.$article->body.'</article>');

        return Inertia::render('public/help/show', [
            'article' => [
                'title' => $article->title,
                'category' => $article->category,
                'body' => $article->body,
            ],
            'related' => $this->shown()
                ->filter(fn (HelpArticle $a) => $a->category === $article->category && $a->slug !== $main->slug)
                ->sortBy('sort')->take(5)
                ->map(fn ($a) => ['title' => $a->title, 'slug' => $a->slug])
                ->values(),
        ]);
    }

    /** The help centre index as plain HTML for crawlers, grouped by category. */
    private function crawlableIndex(Collection $categories): string
    {
        $html = '<h1>Help center</h1>';

        if ($categories->isEmpty()) {
            return $html.'<p>No articles published yet.</p>';
        }

        foreach ($categories as $category) {
            $html .= '<h2>'.e($category['name'] ?: 'General').'</h2><ul>';

            foreach ($category['articles'] as $article) {
                $html .= '<li><a href="'.e(Url::path('help', $article['slug'])).'">'.e($article['title']).'</a>'
                    .($article['excerpt'] ? ' — '.e($article['excerpt']) : '')
                    .'</li>';
            }

            $html .= '</ul>';
        }

        return $html;
    }
}

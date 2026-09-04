<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\HelpArticle;
use App\Support\SeoManager;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

class HelpController extends Controller
{
    /** Help center home — searchable, grouped by category (server-rendered). */
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        $articles = HelpArticle::published()
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('title', 'like', "%{$q}%")
                ->orWhere('excerpt', 'like', "%{$q}%")
                ->orWhere('body', 'like', "%{$q}%")))
            ->orderBy('category')->orderBy('sort')->orderBy('title')
            ->get(['category', 'title', 'slug', 'excerpt']);

        $categories = $articles->groupBy('category')->map(fn ($items, $name) => [
            'name' => $name,
            'articles' => $items->map(fn ($a) => ['title' => $a->title, 'slug' => $a->slug, 'excerpt' => $a->excerpt])->values(),
        ])->values();

        app(SeoManager::class)
            ->title($q !== '' ? "Help — “{$q}”" : 'Help center')
            ->description('Answers and guides for buying tickets, organizing events and managing your DropRSVP account.')
            ->canonical(url('/help'))
            ->breadcrumb([['name' => 'Home', 'url' => url('/')], ['name' => 'Help center', 'url' => url('/help')]]);
        if ($q !== '') {
            app(SeoManager::class)->noindex();
        }

        return Inertia::render('public/help/index', [
            'categories' => $categories,
            'filters' => ['q' => $q],
        ]);
    }

    /** A single help article. */
    public function show(HelpArticle $article)
    {
        abort_unless($article->status === 'published', 404);

        $description = $article->excerpt ?: Str::limit(trim(strip_tags((string) $article->body)), 155);
        $canonical = url('/help/'.$article->slug);

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
                ['name' => 'Home', 'url' => url('/')],
                ['name' => 'Help center', 'url' => url('/help')],
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
            'related' => HelpArticle::published()
                ->where('category', $article->category)
                ->where('id', '!=', $article->id)
                ->orderBy('sort')->limit(5)
                ->get(['title', 'slug'])
                ->map(fn ($a) => ['title' => $a->title, 'slug' => $a->slug]),
        ]);
    }
}

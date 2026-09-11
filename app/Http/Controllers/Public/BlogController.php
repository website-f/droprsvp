<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\CmsCategory;
use App\Models\CmsPost;
use App\Support\SeoManager;
use App\Support\SiteContent;
use App\Support\TableOfContents;
use App\Support\Url;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

class BlogController extends Controller
{
    /** Blog index — published posts, newest first, optionally by category. */
    public function index(Request $request)
    {
        $categorySlug = trim((string) $request->query('category', ''));
        $active = $categorySlug !== '' ? CmsCategory::where('slug', $categorySlug)->first() : null;
        abort_if($categorySlug !== '' && ! $active, 404);

        $posts = CmsPost::published()
            ->with('category:id,name,slug')
            ->when($active, fn ($q) => $q->where('category_id', $active->id))
            ->orderByDesc('published_at')
            ->paginate(9)
            ->withQueryString()
            ->through(fn ($p) => $this->card($p));

        $site = config('seo.site_name', 'DropRSVP');
        $title = $active ? "{$active->name} · Blog" : 'Blog';
        $canonical = $active ? Url::to('blog').'?category='.$active->slug : Url::to('blog');

        app(SeoManager::class)
            ->title($title)
            ->description($active
                ? "{$active->name} articles, guides and stories from {$site}."
                : "News, guides and stories from {$site}.")
            ->canonical($canonical)
            ->type('website')
            ->schema([
                '@type' => ['CollectionPage', 'Blog'],
                'name' => "{$title} · {$site}",
                'url' => $canonical,
                'isPartOf' => ['@id' => url('/#website')],
            ])
            ->breadcrumb(array_values(array_filter([
                ['name' => 'Home', 'url' => Url::to()],
                ['name' => 'Blog', 'url' => Url::to('blog')],
                $active ? ['name' => $active->name, 'url' => $canonical] : null,
            ])));

        return Inertia::render('public/blog/index', [
            'posts' => $posts,
            'sidebar' => $this->sidebar(),
            'activeCategory' => $active?->slug,
            'seo' => ['title' => $title],
        ]);
    }

    /** A single article (server-rendered + Article JSON-LD). */
    public function show(CmsPost $post)
    {
        // published() also guards the date, so a scheduled post can't be reached
        // early by guessing its URL.
        abort_unless(CmsPost::published()->whereKey($post->id)->exists(), 404);
        $post->load(['seo', 'category', 'author']);

        // Anchor the headings and resolve any inline [data-toc] block the author
        // inserted. The processed HTML is what both the page and the crawler get.
        $toc = TableOfContents::build($post->body);

        $seo = $post->seo;
        $description = $seo?->meta_description ?: ($post->excerpt ?: Str::limit(trim(strip_tags((string) $post->body)), 155));
        $canonical = $seo?->canonical_url ?: Url::to('blog', $post->slug);
        $cover = $post->cover_image ? $this->absolute($post->cover_image) : null;
        $wordCount = str_word_count(strip_tags((string) $post->body));

        app(SeoManager::class)
            ->title($seo?->seo_title ?: $post->title)
            ->description($description)
            ->keywords($seo?->meta_keywords)
            ->canonical($canonical)
            ->image($seo?->og_image ?: $cover)
            ->article([
                'published_time' => optional($post->published_at)->toIso8601String(),
                'modified_time' => optional($post->updated_at)->toIso8601String(),
                'section' => $post->category?->name,
                'author' => $post->author?->name,
            ])
            ->robots((bool) ($seo->robots_index ?? true), (bool) ($seo->robots_follow ?? true))
            ->schema($this->postSchema($post, $description, $cover, $canonical, $wordCount))
            ->breadcrumb([
                ['name' => 'Home', 'url' => Url::to()],
                ['name' => 'Blog', 'url' => Url::to('blog')],
                ['name' => $post->title, 'url' => $canonical],
            ])
            // Server-render the post so non-JS crawlers index the real content.
            ->crawlable('<article><h1>'.e($post->title).'</h1>'.$toc['html'].'</article>');

        return Inertia::render('public/blog/show', [
            'post' => [
                'title' => $post->title,
                'body' => $toc['html'],
                'cover_image' => $post->cover_image,
                'category' => $post->category?->name,
                'author' => $post->author?->name,
                'date' => optional($post->published_at)->format('j M Y'),
                'reading_minutes' => max(1, (int) ceil($wordCount / 200)),
            ],
            // Sticky contents rail — the same headings the inline block lists.
            'toc' => $toc['items'],
            // The author already placed a contents block in the body, so the
            // page shouldn't add its own on top of it.
            'hasInlineToc' => str_contains((string) $toc['html'], 'class="post-toc"'),
            'sidebar' => $this->sidebar($post->id, $post->category_id),
            'seo' => ['title' => $seo?->seo_title ?: $post->title],
        ]);
    }

    /**
     * Everything the blog's right-hand rail needs: the categories a reader can
     * switch between (with counts), the latest posts, and the admin-managed ad
     * slot. Shared by the index and the article page so the rail is identical.
     */
    private function sidebar(?int $excludePostId = null, ?int $relatedToCategory = null): array
    {
        $counts = CmsPost::published()
            ->selectRaw('category_id, count(*) as total')
            ->whereNotNull('category_id')
            ->groupBy('category_id')
            ->pluck('total', 'category_id');

        $categories = CmsCategory::whereIn('id', $counts->keys())
            ->orderBy('name')
            ->get(['id', 'name', 'slug'])
            ->map(fn (CmsCategory $c) => ['name' => $c->name, 'slug' => $c->slug, 'count' => (int) $counts[$c->id]])
            ->all();

        $recent = CmsPost::published()
            ->with('category:id,name,slug')
            ->when($excludePostId, fn ($q) => $q->whereKeyNot($excludePostId))
            ->orderByDesc('published_at')
            ->limit(5)
            ->get()
            ->map(fn (CmsPost $p) => $this->card($p))
            ->all();

        // More from the same category — the "related" rail on an article page.
        $related = $relatedToCategory
            ? CmsPost::published()
                ->with('category:id,name,slug')
                ->where('category_id', $relatedToCategory)
                ->when($excludePostId, fn ($q) => $q->whereKeyNot($excludePostId))
                ->orderByDesc('published_at')
                ->limit(4)
                ->get()
                ->map(fn (CmsPost $p) => $this->card($p))
                ->all()
            : [];

        return [
            'categories' => $categories,
            'recent' => $recent,
            'related' => $related,
            'ad' => SiteContent::blogAd(),
        ];
    }

    /** One post as list/card data. */
    private function card(CmsPost $p): array
    {
        return [
            'title' => $p->title,
            'slug' => $p->slug,
            'excerpt' => $p->excerpt ?: Str::limit(trim(strip_tags((string) $p->body)), 140),
            'cover_image' => $p->cover_image,
            'category' => $p->category?->name,
            'category_slug' => $p->category?->slug,
            'date' => optional($p->published_at)->format('j M Y'),
        ];
    }

    private function postSchema(CmsPost $post, string $description, ?string $cover, string $canonical, int $wordCount): array
    {
        return [
            '@type' => 'BlogPosting',
            'headline' => $post->title,
            'description' => $description,
            'url' => $canonical,
            'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $canonical],
            'image' => $cover ? [$cover] : null,
            'datePublished' => optional($post->published_at)->toIso8601String(),
            'dateModified' => optional($post->updated_at)->toIso8601String(),
            'author' => $post->author ? ['@type' => 'Person', 'name' => $post->author->name] : null,
            'publisher' => ['@id' => url('/#organization')],
            'articleSection' => $post->category?->name,
            'wordCount' => $wordCount ?: null,
            'timeRequired' => $wordCount ? 'PT'.max(1, (int) ceil($wordCount / 200)).'M' : null, // ~200 wpm
            'inLanguage' => str_replace('_', '-', (string) config('seo.locale', 'en_US')),
        ];
    }

    private function absolute(string $path): string
    {
        return Str::startsWith($path, ['http://', 'https://']) ? $path : url($path);
    }
}

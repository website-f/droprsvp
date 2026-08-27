<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\CmsPost;
use App\Support\SeoManager;
use Illuminate\Support\Str;
use Inertia\Inertia;

class BlogController extends Controller
{
    /** Blog index — published posts, newest first (server-rendered). */
    public function index()
    {
        $posts = CmsPost::published()
            ->with('category:id,name')
            ->orderByDesc('published_at')
            ->paginate(10)
            ->through(fn ($p) => [
                'title' => $p->title,
                'slug' => $p->slug,
                'excerpt' => $p->excerpt ?: Str::limit(trim(strip_tags((string) $p->body)), 140),
                'cover_image' => $p->cover_image,
                'category' => $p->category?->name,
                'date' => optional($p->published_at)->format('j M Y'),
            ]);

        $site = config('seo.site_name', 'DropRSVP');
        app(SeoManager::class)
            ->title('Blog')
            ->description("News, guides and stories from {$site}.")
            ->canonical(url('/blog'))
            ->type('website')
            ->schema([
                '@type' => ['CollectionPage', 'Blog'],
                'name' => "Blog · {$site}",
                'url' => url('/blog'),
                'isPartOf' => ['@id' => url('/#website')],
            ])
            ->breadcrumb([
                ['name' => 'Home', 'url' => url('/')],
                ['name' => 'Blog', 'url' => url('/blog')],
            ]);

        return Inertia::render('public/blog/index', [
            'posts' => $posts,
            'seo' => ['title' => 'Blog'],
        ]);
    }

    /** A single article (server-rendered + Article JSON-LD). */
    public function show(CmsPost $post)
    {
        abort_unless($post->status === 'published', 404);
        $post->load(['seo', 'category', 'author']);

        $seo = $post->seo;
        $description = $seo?->meta_description ?: ($post->excerpt ?: Str::limit(trim(strip_tags((string) $post->body)), 155));
        $canonical = $seo?->canonical_url ?: url('/blog/'.$post->slug);
        $cover = $post->cover_image ? $this->absolute($post->cover_image) : null;
        $wordCount = str_word_count(strip_tags((string) $post->body));

        app(SeoManager::class)
            ->title($seo?->seo_title ?: $post->title)
            ->description($description)
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
                ['name' => 'Home', 'url' => url('/')],
                ['name' => 'Blog', 'url' => url('/blog')],
                ['name' => $post->title, 'url' => $canonical],
            ]);

        return Inertia::render('public/blog/show', [
            'post' => [
                'title' => $post->title,
                'body' => $post->body,
                'cover_image' => $post->cover_image,
                'category' => $post->category?->name,
                'author' => $post->author?->name,
                'date' => optional($post->published_at)->format('j M Y'),
            ],
            'seo' => ['title' => $seo?->seo_title ?: $post->title],
        ]);
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

<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\CmsPost;
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

        return Inertia::render('public/blog/index', [
            'posts' => $posts,
            'seo' => [
                'title' => 'Blog · DropRSVP',
                'description' => 'News, guides and stories from DropRSVP.',
                'canonical' => url('/blog'),
            ],
        ]);
    }

    /** A single article (server-rendered + Article JSON-LD). */
    public function show(CmsPost $post)
    {
        abort_unless($post->status === 'published', 404);
        $post->load(['seo', 'category', 'author']);

        $description = $post->seo?->meta_description ?: ($post->excerpt ?: Str::limit(trim(strip_tags((string) $post->body)), 155));
        $canonical = $post->seo?->canonical_url ?: url('/blog/'.$post->slug);
        $cover = $post->cover_image;

        return Inertia::render('public/blog/show', [
            'post' => [
                'title' => $post->title,
                'body' => $post->body,
                'cover_image' => $cover,
                'category' => $post->category?->name,
                'author' => $post->author?->name,
                'date' => optional($post->published_at)->format('j M Y'),
            ],
            'seo' => [
                'title' => $post->seo?->seo_title ?: $post->title,
                'description' => $description,
                'canonical' => $canonical,
                'og_image' => $post->seo?->og_image ?: $cover,
                'robots' => $post->seo?->robotsDirective() ?? 'index, follow',
            ],
            'schema' => array_filter([
                '@context' => 'https://schema.org',
                '@type' => 'BlogPosting',
                'headline' => $post->title,
                'description' => $description,
                'url' => $canonical,
                'image' => $cover ? [$cover] : null,
                'datePublished' => optional($post->published_at)->toIso8601String(),
                'author' => $post->author ? ['@type' => 'Person', 'name' => $post->author->name] : null,
            ], fn ($v) => $v !== null),
        ]);
    }
}

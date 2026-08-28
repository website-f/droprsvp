<?php

namespace App\Support;

use App\Models\CmsPost;
use Illuminate\Support\Str;

/** Published blog posts as card data — feeds the Puck "Posts" widget. */
class PostCards
{
    public static function recent(int $limit = 12): array
    {
        return CmsPost::published()
            ->with('category:id,name')
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get()
            ->map(fn ($p) => [
                'title' => $p->title,
                'slug' => $p->slug,
                'excerpt' => $p->excerpt ?: Str::limit(trim(strip_tags((string) $p->body)), 140),
                'cover_image' => $p->cover_image,
                'category' => $p->category?->name,
                'date' => optional($p->published_at)->format('j M Y'),
            ])
            ->all();
    }
}

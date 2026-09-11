<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;

class CmsPost extends Model
{
    use SoftDeletes;

    protected $fillable = ['author_id', 'category_id', 'title', 'slug', 'excerpt', 'body', 'cover_image', 'status', 'published_at'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    protected static function booted(): void
    {
        // The footer's "From the blog" list is cached and shared on every page,
        // so any edit has to drop it.
        static::saved(fn () => Cache::forget(self::FOOTER_CACHE));
        static::deleted(fn () => Cache::forget(self::FOOTER_CACHE));
    }

    private const FOOTER_CACHE = 'blog.footer-latest';

    /**
     * The newest posts for the site footer — title + slug only. Cached, and
     * short-lived rather than forever because a scheduled post goes live on a
     * timer rather than on a save that could bust the cache.
     *
     * @return array<int, array{title: string, slug: string}>
     */
    public static function forFooter(int $limit = 3): array
    {
        return Cache::remember(self::FOOTER_CACHE, now()->addMinutes(10), fn () => static::published()
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get(['title', 'slug'])
            ->map(fn (self $p) => ['title' => $p->title, 'slug' => $p->slug])
            ->all());
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CmsCategory::class, 'category_id');
    }

    public function seo(): MorphOne
    {
        return $this->morphOne(SeoMeta::class, 'seoable');
    }

    /**
     * Live posts only. A scheduled post carries status "scheduled" until the
     * `posts:publish-scheduled` task flips it, but the date is checked here too:
     * if that cron is late or missing, a post dated in the future still must not
     * leak onto the site, the sitemap or the feeds.
     */
    public function scopePublished(Builder $q): Builder
    {
        return $q->where('status', 'published')
            ->where(fn (Builder $w) => $w->whereNull('published_at')->orWhere('published_at', '<=', now()));
    }

    /** Waiting for its publish date to arrive. */
    public function isScheduled(): bool
    {
        return $this->status === 'scheduled';
    }

    /** Posts whose scheduled moment has arrived and are due to go live. */
    public function scopeDue(Builder $q): Builder
    {
        return $q->where('status', 'scheduled')->whereNotNull('published_at')->where('published_at', '<=', now());
    }
}

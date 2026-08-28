<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * SEO metadata for any content model (page, post, event). Attach with a
 * `morphOne(SeoMeta::class, 'seoable')` relation and edit via the SEO panel.
 * Blank fields fall back to the parent's title/excerpt at render time.
 */
class SeoMeta extends Model
{
    protected $table = 'seo_meta';

    protected $fillable = [
        'seo_title', 'meta_description', 'slug', 'focus_keyphrase', 'meta_keywords', 'canonical_url',
        'robots_index', 'robots_follow', 'og_title', 'og_description', 'og_image',
        'twitter_card', 'breadcrumb_title', 'schema_type', 'schema_overrides',
    ];

    protected function casts(): array
    {
        return [
            'robots_index' => 'boolean',
            'robots_follow' => 'boolean',
            'schema_overrides' => 'array',
        ];
    }

    public function seoable(): MorphTo
    {
        return $this->morphTo();
    }

    /** The `robots` meta content, e.g. "index, follow" / "noindex, nofollow". */
    public function robotsDirective(): string
    {
        return ($this->robots_index ? 'index' : 'noindex').', '.($this->robots_follow ? 'follow' : 'nofollow');
    }
}

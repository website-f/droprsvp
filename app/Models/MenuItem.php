<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class MenuItem extends Model
{
    protected $fillable = ['location', 'label', 'url', 'new_tab', 'sort'];

    protected $casts = [
        'new_tab' => 'boolean',
        'sort' => 'integer',
    ];

    protected static function booted(): void
    {
        // Keep the cached public navigation in sync with any change.
        static::saved(fn () => Cache::forget('nav.header'));
        static::deleted(fn () => Cache::forget('nav.header'));
    }

    /** The header navigation, ordered — cached for the public site. */
    public static function header(): array
    {
        return Cache::rememberForever('nav.header', fn () => static::query()
            ->where('location', 'header')
            ->orderBy('sort')
            ->orderBy('id')
            ->get(['label', 'url', 'new_tab'])
            ->map(fn ($i) => ['label' => $i->label, 'url' => $i->url, 'new_tab' => $i->new_tab])
            ->all());
    }
}

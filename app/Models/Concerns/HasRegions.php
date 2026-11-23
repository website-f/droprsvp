<?php

namespace App\Models\Concerns;

use App\Support\Regions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Content shown by region (pages, posts, help articles).
 *
 * `regions` lists the region codes it is shown in; empty means every region.
 * Models with a `parent_id` column also have REGION VERSIONS: rows that
 * belong to a main one and replace it for the regions they list — the
 * Singapore pricing page, the Indonesian terms — at the same address.
 */
trait HasRegions
{
    public function initializeHasRegions(): void
    {
        $this->mergeCasts(['regions' => 'array']);
    }

    /** Normalise on save: lowercase, only real codes, null for "every region". */
    public function setRegionsAttribute(mixed $value): void
    {
        $codes = array_values(array_unique(array_filter(
            array_map(fn ($c) => strtolower(trim((string) $c)), (array) $value),
            fn ($c) => preg_match('/^'.Regions::PATTERN.'$/', $c),
        )));
        sort($codes);

        $this->attributes['regions'] = $codes ? json_encode($codes) : null;
    }

    /** Shown in this region (the one being browsed, by default). */
    public function scopeShownIn(Builder $q, ?string $region = null): Builder
    {
        $region ??= Regions::current()['code'];

        return $q->where(fn (Builder $w) => $w->whereNull('regions')->orWhereJsonContains('regions', $region));
    }

    public function isShownIn(?string $region = null): bool
    {
        $region ??= Regions::current()['code'];

        return empty($this->regions) || in_array($region, (array) $this->regions, true);
    }

    /** The main rows only — not region versions. */
    public function scopeMain(Builder $q): Builder
    {
        return $q->whereNull('parent_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(static::class, 'parent_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(static::class, 'parent_id')->orderBy('id');
    }

    /**
     * What a visitor in `$region` gets of this (main) row: the region version
     * that lists their region, else this row if it is shown there, else
     * nothing. `$published` narrows versions with the model's published scope.
     */
    public function versionFor(?string $region = null, bool $published = true): ?static
    {
        $region ??= Regions::current()['code'];

        $version = $this->versions()
            ->when($published, fn ($q) => $q->published())
            ->whereJsonContains('regions', $region)
            ->first();

        return $version ?? ($this->isShownIn($region) ? $this : null);
    }

    /** The main row's slug, for a version (which has none of its own). */
    public function addressSlug(): ?string
    {
        return $this->parent_id ? $this->parent?->slug : $this->slug;
    }
}

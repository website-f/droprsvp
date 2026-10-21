<?php

namespace App\Models;

use App\Support\Dates;
use App\Support\ImageOptimizer;
use App\Support\RolePermissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One image in the Media Library.
 *
 * Every image uploaded through /uploads is recorded here, so it can be found
 * and reused: picked in the page and email builders, or inserted into a page
 * or email written in HTML mode.
 *
 * Who sees what: admins with the Content section (and superadmins) see every
 * image; anyone else — an organizer building an email, say — sees only the
 * ones they uploaded.
 */
class Media extends Model
{
    protected $table = 'media';

    protected $fillable = ['user_id', 'path', 'original_name', 'alt', 'width', 'height', 'size'];

    protected function casts(): array
    {
        return ['width' => 'integer', 'height' => 'integer', 'size' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Whether this person manages the whole library rather than their own corner of it. */
    public static function managesAll(?User $user): bool
    {
        return RolePermissions::can($user, 'content');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return self::managesAll($user) ? $query : $query->where('user_id', $user->id);
    }

    public function editableBy(User $user): bool
    {
        return self::managesAll($user) || ($this->user_id !== null && $this->user_id === $user->id);
    }

    /**
     * Record an image already on the public disk. Safe to repeat for the same
     * path: the row is found rather than duplicated.
     */
    public static function record(string $path, ?int $userId = null, ?string $originalName = null): self
    {
        $disk = Storage::disk('public');
        $absolute = $disk->path($path);
        $info = @getimagesize($absolute);

        return self::firstOrCreate(['path' => $path], [
            'user_id' => $userId,
            'original_name' => self::cleanName($originalName ?: basename($path)),
            'width' => $info[0] ?? null,
            'height' => $info[1] ?? null,
            'size' => is_file($absolute) ? (int) filesize($absolute) : null,
        ]);
    }

    /** A filename as people will read it in the library: no folders, no oddities, not too long. */
    public static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[^\pL\pN ._()\-]+/u', '', $name);

        return mb_substr(trim($name), 0, 191) ?: 'image';
    }

    public function url(): string
    {
        return Storage::disk('public')->url($this->path);
    }

    /** The grid-sized copy when there is one (see ImageOptimizer::thumbnail). */
    public function thumbUrl(): string
    {
        $thumb = trim(dirname($this->path), './').'/'.ImageOptimizer::THUMB_DIR.'/'.basename($this->path);

        return Storage::disk('public')->exists($thumb) ? Storage::disk('public')->url($thumb) : $this->url();
    }

    /** What the library and the picker are given about one image. */
    public function payload(?User $viewer = null): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url(),
            'thumb' => $this->thumbUrl(),
            'name' => $this->original_name ?: basename($this->path),
            'alt' => $this->alt,
            'width' => $this->width,
            'height' => $this->height,
            'size' => $this->size,
            'uploaded' => Dates::display($this->created_at, 'j M Y'),
            'uploader' => $this->user?->name,
            'editable' => $viewer ? $this->editableBy($viewer) : false,
        ];
    }
}

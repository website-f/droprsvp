<?php

namespace App\Services\Chat;

use App\Support\Chat\ChatSettings;
use App\Support\ImageOptimizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Chat images: kept on the PRIVATE disk (storage/app/private), never under
 * public/, so a photo sent in a conversation has no guessable URL. They are
 * served by ChatMediaController to the two people in it, and to moderators
 * looking at a report.
 *
 * Each upload is re-encoded to at most 1600px on the long edge (a phone photo
 * is 4000px and several megabytes) with a 480px thumbnail for the bubble, so a
 * busy thread stays light on a mobile connection and on the host's disk.
 */
class Attachments
{
    private const DISK = 'local';

    private const MAX_EDGE = 1600;

    private const THUMB_EDGE = 480;

    public const MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    /** @return array{path: string, width: int, height: int, size: int, mime: string} */
    public function store(UploadedFile $file): array
    {
        $maxBytes = (int) ChatSettings::get('max_image_mb') * 1024 * 1024;

        if (! $file->isValid() || $file->getSize() > $maxBytes) {
            throw new RuntimeException('Images can be up to '.ChatSettings::get('max_image_mb').' MB.');
        }

        // Trust the bytes, not the name or the browser's claim.
        $info = @getimagesize($file->getRealPath());
        $mime = $info['mime'] ?? null;

        if (! $info || ! in_array($mime, self::MIMES, true)) {
            throw new RuntimeException('Only JPG, PNG, WebP or GIF images can be sent.');
        }

        $ext = match ($mime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'jpg',
        };

        $path = 'chat/'.now()->format('Y/m').'/'.Str::random(32).'.'.$ext;
        Storage::disk(self::DISK)->putFileAs(dirname($path), $file, basename($path));

        $absolute = Storage::disk(self::DISK)->path($path);
        ImageOptimizer::optimise($absolute, self::MAX_EDGE);
        ImageOptimizer::thumbnail($absolute, self::THUMB_EDGE);

        $final = @getimagesize($absolute) ?: $info;

        return [
            'path' => $path,
            'width' => (int) $final[0],
            'height' => (int) $final[1],
            'size' => (int) (@filesize($absolute) ?: $file->getSize()),
            'mime' => $mime,
        ];
    }

    /** Absolute path of a variant, falling back to the original when no thumbnail was made. */
    public function absolute(string $path, string $variant): ?string
    {
        $disk = Storage::disk(self::DISK);

        if (! $disk->exists($path)) {
            return null;
        }

        $original = $disk->path($path);

        if ($variant === 'thumb') {
            $thumb = ImageOptimizer::thumbPath($original);

            return is_file($thumb) ? $thumb : $original;
        }

        return $original;
    }

    public function delete(?string $path): void
    {
        if (! $path) {
            return;
        }

        $disk = Storage::disk(self::DISK);
        $thumb = ImageOptimizer::thumbPath($disk->path($path));

        $disk->delete($path);

        if (is_file($thumb)) {
            @unlink($thumb);
        }
    }
}

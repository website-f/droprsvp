<?php

namespace App\Console\Commands;

use App\Models\Media;
use App\Support\ImageOptimizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Put images uploaded before the Media Library existed into it.
 *
 * Walks the public disk's upload folder and records every image it finds
 * (thumbnails excepted). Who uploaded them was never stored, so they belong
 * to nobody: admins see them, organizers do not. Safe to run more than once —
 * an image already in the library is skipped. Run by the migration that
 * created the table.
 */
class ImportMediaLibrary extends Command
{
    protected $signature = 'media:import {--dir=cms : Folder on the public disk to look in}';

    protected $description = 'Add images already on the public disk to the Media Library';

    public function handle(): int
    {
        $disk = Storage::disk('public');
        $dir = trim((string) $this->option('dir'), '/');

        if (! $disk->exists($dir)) {
            $this->info("Nothing to import: {$dir}/ does not exist.");

            return self::SUCCESS;
        }

        $known = Media::pluck('path')->flip();
        $added = 0;

        foreach ($disk->allFiles($dir) as $path) {
            if (str_contains($path, '/'.ImageOptimizer::THUMB_DIR.'/')
                || ! preg_match('/\.(jpe?g|png|webp|gif)$/i', $path)
                || isset($known[$path])) {
                continue;
            }

            $media = Media::record($path);

            // Dated by the file, not the import, so the library's month filter
            // puts an old image where it belongs.
            $modified = @filemtime($disk->path($path));
            if ($modified) {
                $media->timestamps = false;
                $media->forceFill(['created_at' => date('Y-m-d H:i:s', $modified), 'updated_at' => date('Y-m-d H:i:s', $modified)])->save();
            }

            $added++;
        }

        $this->info("Added {$added} image(s) to the Media Library.");

        return self::SUCCESS;
    }
}

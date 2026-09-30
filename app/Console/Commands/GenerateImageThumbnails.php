<?php

namespace App\Console\Commands;

use App\Support\ImageOptimizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Build the grid thumbnails for images that were uploaded before thumbnails
 * existed.
 *
 * New uploads get one on the way in (MediaController), but every image already
 * on disk does not — and those are the galleries that are slow today. The
 * frontend falls back to the full image when a thumbnail is missing, so this is
 * purely a speed backfill: nothing breaks if it is never run, and nothing is
 * overwritten if it is run twice.
 *
 *   php artisan images:thumbnails            build the missing ones
 *   php artisan images:thumbnails --force    rebuild every one
 *
 * Unlike images:optimize this never touches the original, so there is no
 * --dry-run: the worst case is some extra files under cms/thumbs/.
 */
class GenerateImageThumbnails extends Command
{
    protected $signature = 'images:thumbnails
        {--dir=cms : Directory on the public disk to walk}
        {--force : Rebuild thumbnails that already exist}
        {--max-edge= : Override the longest edge of the thumbnail}';

    protected $description = 'Generate grid-sized thumbnails for images already stored on the public disk';

    public function handle(): int
    {
        if (! ImageOptimizer::available()) {
            $this->error('GD is not available on this PHP build, so no thumbnails can be generated.');

            return self::FAILURE;
        }

        $disk = Storage::disk('public');
        $directory = trim((string) $this->option('dir'), '/');
        $maxEdge = (int) ($this->option('max-edge') ?: ImageOptimizer::THUMB_EDGE);

        $files = collect($disk->allFiles($directory))
            // Skip the thumbnails themselves, or a second run would make
            // thumbnails of thumbnails.
            ->reject(fn ($file) => str_contains($file, '/'.ImageOptimizer::THUMB_DIR.'/'))
            ->filter(fn ($file) => in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp'], true));

        if ($files->isEmpty()) {
            $this->info("No images found in {$directory}/.");

            return self::SUCCESS;
        }

        $this->info('Generating thumbnails for '.$files->count()." image(s) in {$directory}/ …");

        $bar = $this->output->createProgressBar($files->count());
        $made = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($files as $file) {
            $path = $disk->path($file);
            $thumb = ImageOptimizer::thumbPath($path);
            $existed = is_file($thumb);

            if ($existed && ! $this->option('force')) {
                $skipped++;
                $bar->advance();

                continue;
            }

            if ($existed) {
                @unlink($thumb);
            }

            ImageOptimizer::thumbnail($path, $maxEdge) ? $made++ : $failed++;

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->line("  Generated: {$made}");
        $this->line("  Already had one: {$skipped}");

        if ($failed > 0) {
            $this->line("  Could not be thumbnailed (served full-size instead): {$failed}");
        }

        return self::SUCCESS;
    }
}

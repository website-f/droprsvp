<?php

namespace App\Console\Commands;

use App\Support\ImageOptimizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Shrink images that were uploaded before uploads were optimised.
 *
 * New uploads are downscaled on the way in, but everything already on disk is
 * still the full-size original a phone produced — which is the gallery that is
 * slow today. This rewrites them in place.
 *
 *   php artisan images:optimize --dry-run    see what it would do
 *   php artisan images:optimize              do it
 *
 * Rewriting in place means there is no undo, so --dry-run first. Files are only
 * replaced when the result is genuinely smaller, and any file that cannot be
 * decoded is skipped untouched.
 */
class OptimizeStoredImages extends Command
{
    protected $signature = 'images:optimize
        {--dry-run : Report what would change without writing anything}
        {--dir=cms : Directory on the public disk to walk}
        {--max-edge= : Override the longest edge to keep}';

    protected $description = 'Downscale and re-compress images already stored on the public disk';

    public function handle(): int
    {
        if (! ImageOptimizer::available()) {
            $this->error('GD is not available on this PHP build, so nothing can be resized.');

            return self::FAILURE;
        }

        $disk = Storage::disk('public');
        $directory = trim((string) $this->option('dir'), '/');
        $maxEdge = (int) ($this->option('max-edge') ?: ImageOptimizer::MAX_EDGE);
        $dryRun = (bool) $this->option('dry-run');

        $files = collect($disk->allFiles($directory))
            ->filter(fn ($file) => in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp'], true));

        if ($files->isEmpty()) {
            $this->info("No images found in {$directory}/.");

            return self::SUCCESS;
        }

        $this->info(($dryRun ? 'Checking ' : 'Optimising ').$files->count()." image(s) in {$directory}/ …");

        $bar = $this->output->createProgressBar($files->count());
        $saved = 0;
        $changed = 0;
        $skipped = 0;

        foreach ($files as $file) {
            $path = $disk->path($file);
            $before = @filesize($path) ?: 0;

            if ($dryRun) {
                // Measure without writing: copy aside, optimise the copy, compare.
                $probe = $path.'.probe';

                if (@copy($path, $probe)) {
                    if (ImageOptimizer::optimise($probe, $maxEdge)) {
                        $changed++;
                        $saved += $before - (@filesize($probe) ?: $before);
                    } else {
                        $skipped++;
                    }

                    @unlink($probe);
                } else {
                    $skipped++;
                }
            } elseif (ImageOptimizer::optimise($path, $maxEdge)) {
                $changed++;
                $saved += $before - (@filesize($path) ?: $before);
            } else {
                $skipped++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->line("  Changed: {$changed}");
        $this->line("  Left as they were: {$skipped}");
        $this->line('  Saved: '.$this->bytes($saved));

        if ($dryRun) {
            $this->newLine();
            $this->comment('Dry run — nothing was written. Re-run without --dry-run to apply.');
        }

        return self::SUCCESS;
    }

    private function bytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $power = min((int) floor(log($bytes, 1024)), count($units) - 1);

        return round($bytes / (1024 ** $power), 1).' '.$units[$power];
    }
}

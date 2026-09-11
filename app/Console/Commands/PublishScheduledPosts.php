<?php

namespace App\Console\Commands;

use App\Models\CmsPost;
use Illuminate\Console\Command;

/**
 * Flip scheduled blog posts live once their publish date arrives — the
 * WordPress "future post" behaviour. Driven by the scheduler, so it needs the
 * same single cPanel cron as the stale-cart reaper (see DEPLOY.md).
 *
 * CmsPost::published() also filters on the date, so a post can never appear
 * early even if this task is delayed; this command exists so the stored status
 * catches up and the admin list reads correctly.
 */
class PublishScheduledPosts extends Command
{
    protected $signature = 'posts:publish-scheduled';

    protected $description = 'Publish blog posts whose scheduled date has arrived';

    public function handle(): int
    {
        $due = CmsPost::due()->get();

        foreach ($due as $post) {
            $post->forceFill(['status' => 'published'])->save();
            $this->info("Published: {$post->title}");
        }

        $this->info($due->isEmpty() ? 'No posts were due.' : $due->count().' post(s) published.');

        return self::SUCCESS;
    }
}

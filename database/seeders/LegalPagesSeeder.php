<?php

namespace Database\Seeders;

use App\Models\CmsPage;
use App\Support\LegalDefaults;
use Illuminate\Database\Seeder;

/** Seed the Privacy Policy + Terms pages (editable under Admin → Legal pages). */
class LegalPagesSeeder extends Seeder
{
    public function run(): void
    {
        foreach (LegalDefaults::PAGES as $slug => $title) {
            $page = CmsPage::withTrashed()->firstOrNew(['slug' => $slug]);

            if (! $page->exists) {
                $page->title = $title;
                $page->body = LegalDefaults::body($slug);
                $page->status = 'published';
                $page->published_at = now();
                $page->save();
                $page->seo()->firstOrCreate([], [
                    'meta_description' => "Read the {$title} for ".config('seo.site_name', config('app.name')).'.',
                    'robots_index' => true,
                    'robots_follow' => true,
                ]);
            }
        }
    }
}

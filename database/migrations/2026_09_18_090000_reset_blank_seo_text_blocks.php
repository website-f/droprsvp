<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

/**
 * The landing + events-page SEO text blocks now ship with premade, enabled copy.
 * Earlier the default was an empty, disabled block, and saving those settings could
 * persist that blank/disabled state — which would suppress the new premade copy.
 *
 * Strip only a *blank* stored seo_text so the premade default takes over. Any block
 * where an admin actually wrote content is left untouched, as is a deliberate
 * disable once real copy exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['landing_sections', 'events_page'] as $key) {
            $data = Setting::getArray($key, []);

            if (! isset($data['seo_text'])) {
                continue;
            }

            $body = trim(strip_tags((string) ($data['seo_text']['body'] ?? '')));

            if ($body === '') {
                unset($data['seo_text']);
                Setting::putArray($key, $data);
            }
        }
    }

    public function down(): void
    {
        // Non-reversible data cleanup — nothing to restore.
    }
};

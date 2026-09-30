<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Give every organizer a public handle.
 *
 * Slugs were minted lazily — the first time something happened to render a
 * link to the organizer (an event page, the home page's featured strip, a
 * follow). An approved organizer who had not published an event yet therefore
 * had no slug at all, which meant:
 *
 *   * /en-my/o/ 404s for them, because the route binds on the slug;
 *   * they are absent from the sitemap;
 *   * `php artisan seo:check` printed "(no slug yet)" against their name,
 *     which is how this surfaced — two of four organizers on production.
 *
 * Approval now mints one (Admin\OrganizerController::approve), so this only
 * repairs the accounts that predate that. Idempotent: it skips anyone who
 * already has a slug, and User::uniqueSlug de-duplicates against what exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'slug')) {
            return;
        }

        // Anyone who hosts: the role is the thing that makes a profile public.
        // Chunked by id so a large account list does not load in one go.
        User::query()
            ->whereNull('slug')
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['organizer', 'superadmin']))
            ->orderBy('id')
            ->chunkById(100, function ($users) {
                foreach ($users as $user) {
                    // ensureSlug() generates, de-duplicates and saves.
                    $user->ensureSlug();
                }
            });
    }

    public function down(): void
    {
        // Nothing to undo: removing slugs would break URLs that are, by then,
        // public and possibly indexed.
    }
};

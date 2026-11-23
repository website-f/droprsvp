<?php

use App\Support\RegionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Content by region.
 *
 * regions   (pages, posts, help articles) which regions show it: a JSON list
 *           of codes like ["en-sg","en-id"]; null = every region.
 * parent_id (pages, help articles) a REGION VERSION of another page — its own
 *           title, content and SEO for the regions it lists, under the same
 *           address. The page it belongs to is the default for every other
 *           region. A version has no slug of its own (it uses its parent's),
 *           which is what lets the slug stay unique.
 * country   (organizer profiles) where the organizer is, as an ISO code, for
 *           the admin's list and filter. Filled for existing organizers from
 *           the country on their account, else where their events are.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['cms_pages', 'help_articles'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                if (! Schema::hasColumn($table, 'parent_id')) {
                    $t->foreignId('parent_id')->nullable()->after('id')->constrained($table)->cascadeOnDelete();
                    $t->json('regions')->nullable()->after('parent_id');
                }
            });

            // A version takes its parent's address, so its own slug is empty.
            Schema::table($table, fn (Blueprint $t) => $t->string('slug')->nullable()->change());
        }

        Schema::table('cms_posts', function (Blueprint $t) {
            if (! Schema::hasColumn('cms_posts', 'regions')) {
                $t->json('regions')->nullable()->after('category_id');
            }
        });

        Schema::table('organizer_profiles', function (Blueprint $t) {
            if (! Schema::hasColumn('organizer_profiles', 'country')) {
                $t->string('country', 2)->nullable()->after('status')->index();
            }
        });

        // Existing organizers: the country on their account (stored as a name),
        // else the region of their latest event, else Malaysia.
        $byName = [];
        foreach (RegionCatalog::COUNTRIES as $code => $row) {
            $byName[strtolower($row[0])] = $code;
        }

        DB::table('organizer_profiles')->whereNull('country')->orderBy('id')->each(function ($profile) use ($byName) {
            $name = strtolower((string) DB::table('users')->where('id', $profile->user_id)->value('country'));
            $region = DB::table('events')->where('user_id', $profile->user_id)->orderByDesc('id')->value('region');
            $code = $byName[$name] ?? ($region ? strtoupper(substr($region, 3)) : 'MY');

            DB::table('organizer_profiles')->where('id', $profile->id)->update(['country' => $code]);
        });
    }

    public function down(): void
    {
        foreach (['cms_pages', 'help_articles'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropConstrainedForeignId('parent_id');
                $t->dropColumn('regions');
            });
        }
        Schema::table('cms_posts', fn (Blueprint $t) => $t->dropColumn('regions'));
        Schema::table('organizer_profiles', fn (Blueprint $t) => $t->dropColumn('country'));
    }
};

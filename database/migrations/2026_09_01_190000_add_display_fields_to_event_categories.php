<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Give categories admin-controllable presentation: the homepage tiles used to
     * hardcode an icon/subtitle/colour per slug in the frontend — now they live on
     * the record so the superadmin owns exactly what shows, and new categories
     * carry their own look everywhere they appear.
     */
    public function up(): void
    {
        Schema::table('event_categories', function (Blueprint $table) {
            $table->string('icon', 40)->nullable()->after('slug');
            $table->string('blurb', 80)->nullable()->after('icon');
            $table->string('color', 20)->nullable()->after('blurb');
        });

        // Backfill the starter set with the exact look they already had on the
        // homepage, so nothing changes visually for existing installs.
        $seed = [
            'music' => ['music', 'Gigs & live sets', '#6c63ff'],
            'business' => ['briefcase', 'Talks & networking', '#2ec4b6'],
            'food-drink' => ['utensils', 'Tastings & festivals', '#f5a524'],
            'tech' => ['cpu', 'Meetups & demos', '#3b82f6'],
            'community' => ['users', 'Local get-togethers', '#ff6584'],
            'sports' => ['dumbbell', 'Games & fitness', '#f97316'],
            'arts' => ['palette', 'Shows & workshops', '#a855f7'],
            'wellness' => ['heart-pulse', 'Yoga & retreats', '#22c55e'],
        ];
        foreach ($seed as $slug => [$icon, $blurb, $color]) {
            DB::table('event_categories')->where('slug', $slug)
                ->update(['icon' => $icon, 'blurb' => $blurb, 'color' => $color]);
        }
    }

    public function down(): void
    {
        Schema::table('event_categories', function (Blueprint $table) {
            $table->dropColumn(['icon', 'blurb', 'color']);
        });
    }
};

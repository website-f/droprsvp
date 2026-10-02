<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An organizer's social profiles: { "instagram": "https://…", "tiktok": "…" }.
 * Only the platforms they actually filled in; see App\Support\SocialLinks.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('organizer_profiles', 'socials')) {
            Schema::table('organizer_profiles', function (Blueprint $table) {
                $table->json('socials')->nullable()->after('website');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('organizer_profiles', 'socials')) {
            Schema::table('organizer_profiles', fn (Blueprint $table) => $table->dropColumn('socials'));
        }
    }
};

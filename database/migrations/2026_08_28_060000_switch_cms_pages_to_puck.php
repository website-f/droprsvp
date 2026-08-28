<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cms_pages', function (Blueprint $table) {
            // Structured page content authored by the Puck page builder.
            $table->json('puck_data')->nullable()->after('layout');
        });

        // Drop the GrapesJS artefact — the builder is now Puck.
        if (Schema::hasColumn('cms_pages', 'builder_css')) {
            Schema::table('cms_pages', function (Blueprint $table) {
                $table->dropColumn('builder_css');
            });
        }
    }

    public function down(): void
    {
        Schema::table('cms_pages', function (Blueprint $table) {
            $table->dropColumn('puck_data');
            $table->longText('builder_css')->nullable();
        });
    }
};

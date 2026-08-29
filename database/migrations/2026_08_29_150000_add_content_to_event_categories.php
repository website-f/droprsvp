<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_categories', function (Blueprint $table) {
            // SEO-friendly copy shown (truncated, "see more") at the bottom of the
            // discovery page for this category.
            $table->text('content')->nullable()->after('sort_order');
        });
    }

    public function down(): void
    {
        Schema::table('event_categories', function (Blueprint $table) {
            $table->dropColumn('content');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seo_meta', function (Blueprint $table) {
            // Comma-separated meta keywords (editable per page/post + homepage).
            $table->string('meta_keywords', 500)->nullable()->after('focus_keyphrase');
        });
    }

    public function down(): void
    {
        Schema::table('seo_meta', function (Blueprint $table) {
            $table->dropColumn('meta_keywords');
        });
    }
};

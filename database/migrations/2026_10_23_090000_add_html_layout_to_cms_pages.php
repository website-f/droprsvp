<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which kind of pasted HTML a page holds:
 *
 *   "content" — partial HTML, shown in the site's own typography;
 *   "full"    — a designed page with its own styles, shown exactly as written.
 *
 * Null means "decide from the HTML": anything with its own <style>,
 * stylesheet or <html>/<body> is a full page, the rest is content. Existing
 * pages are null, so a page like /pricing — pasted content with no styles —
 * picks up the site's styling without being saved again.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('cms_pages', 'html_layout')) {
            Schema::table('cms_pages', function (Blueprint $table) {
                $table->string('html_layout', 20)->nullable()->after('html');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('cms_pages', 'html_layout')) {
            Schema::table('cms_pages', fn (Blueprint $table) => $table->dropColumn('html_layout'));
        }
    }
};

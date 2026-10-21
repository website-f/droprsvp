<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "HTML mode" beside the drag-and-drop page builder: a CMS page can be a
 * whole HTML document pasted in, styles and all, instead of blocks.
 *
 * `editor` says which one a page uses ("builder" or "html"); the pasted
 * document has its own column, so switching back and forth never throws
 * away the other version.
 *
 * Emails need no column: their pasted HTML travels inside the existing
 * `design` JSON, which every send, preview and template copy already
 * carries (App\Support\Edm\Renderer).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('cms_pages', 'editor')) {
            Schema::table('cms_pages', function (Blueprint $table) {
                $table->string('editor', 20)->default('builder')->after('layout');
                $table->longText('html')->nullable()->after('editor');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('cms_pages', 'editor')) {
            Schema::table('cms_pages', fn (Blueprint $table) => $table->dropColumn(['editor', 'html']));
        }
    }
};

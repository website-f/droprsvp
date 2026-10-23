<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a page's pasted HTML may run its scripts in the page itself.
 *
 * True only when a superadmin saved the page last — WordPress draws the same
 * line with its "unfiltered HTML" capability. Scripts in the page act with the
 * viewer's own session, an admin's included, so a page last saved by anyone
 * else keeps its scripts in the sealed frame. Existing pages start untrusted;
 * saving one as a superadmin turns it on.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('cms_pages', 'html_trusted')) {
            Schema::table('cms_pages', function (Blueprint $table) {
                $table->boolean('html_trusted')->default(false)->after('html_layout');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('cms_pages', 'html_trusted')) {
            Schema::table('cms_pages', fn (Blueprint $table) => $table->dropColumn('html_trusted'));
        }
    }
};

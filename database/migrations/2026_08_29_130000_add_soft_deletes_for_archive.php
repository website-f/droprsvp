<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Soft deletes for the entities surfaced in the admin Archive. Events, pages and
 * posts already had `deleted_at`; this adds it to users + both category kinds so
 * every "delete" becomes recoverable (restore / permanent-delete from Archive).
 */
return new class extends Migration
{
    private array $tables = ['users', 'event_categories', 'cms_categories'];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, fn (Blueprint $t) => $t->softDeletes());
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropSoftDeletes());
            }
        }
    }
};

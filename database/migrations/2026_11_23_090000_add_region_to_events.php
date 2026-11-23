<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The region an event belongs to (en-my, en-sg…): which region's pages list
 * it, and the first segment of its URL. Every event so far is Malaysian.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('events', 'region')) {
            Schema::table('events', function (Blueprint $table) {
                $table->string('region', 8)->default('en-my')->after('timezone');
                $table->index(['region', 'status']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('events', 'region')) {
            Schema::table('events', function (Blueprint $table) {
                $table->dropIndex(['region', 'status']);
                $table->dropColumn('region');
            });
        }
    }
};

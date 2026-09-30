<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keep the history of a ticket's check-ins.
 *
 * Undo used to null out checked_in_at and checked_in_by, so after an accidental
 * tap there was nothing left to say the ticket had ever been scanned, who
 * scanned it, or who reversed it. At a door that is the one question worth
 * being able to answer afterwards.
 *
 * A JSON column rather than a table: this is an append-only log read only
 * alongside its own ticket, never queried across tickets.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('tickets', 'check_in_log')) {
            return;
        }

        Schema::table('tickets', function (Blueprint $table) {
            $table->json('check_in_log')->nullable()->after('checked_in_by');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('tickets', 'check_in_log')) {
            Schema::table('tickets', fn (Blueprint $t) => $t->dropColumn('check_in_log'));
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Buyers choosing their own table, and a cheaper price for groups.
 *
 * events.table_choice            buyers pick a table at checkout (tables mode)
 * events.table_choice_limit      only the first N orders may pick; null = everyone
 * events.tables_per_ticket_type  each ticket type is its own sitting (Session A,
 *                                Session B…): a table's seats are counted per type
 * order_items.seating_table_id   the table a buyer picked for that line, held
 *                                while the order is pending, copied to its tickets
 * ticket_types.group_min/price   "buy group_min or more and each ticket costs
 *                                group_price" — one ticket type instead of a
 *                                1-pax and a 3-pax one for every session
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            if (! Schema::hasColumn('events', 'table_choice')) {
                $table->boolean('table_choice')->default(false)->after('auto_assign_tables');
                $table->unsignedInteger('table_choice_limit')->nullable()->after('table_choice');
                $table->boolean('tables_per_ticket_type')->default(false)->after('table_choice_limit');
            }
        });

        Schema::table('order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('order_items', 'seating_table_id')) {
                $table->foreignId('seating_table_id')->nullable()->after('seat_label')->constrained('seating_tables')->nullOnDelete();
            }
        });

        Schema::table('ticket_types', function (Blueprint $table) {
            if (! Schema::hasColumn('ticket_types', 'group_min')) {
                $table->unsignedSmallInteger('group_min')->nullable()->after('compare_at_price');
                $table->decimal('group_price', 10, 2)->nullable()->after('group_min');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ticket_types', fn (Blueprint $table) => $table->dropColumn(['group_min', 'group_price']));
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('seating_table_id');
        });
        Schema::table('events', fn (Blueprint $table) => $table->dropColumn(['table_choice', 'table_choice_limit', 'tables_per_ticket_type']));
    }
};

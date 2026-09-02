<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            // How the event sells: general admission, reserved seating, or banquet tables.
            $table->string('ticketing_mode', 16)->default('general')->after('seating_enabled');
            // Automatically place paid attendees at a table with free capacity on purchase.
            $table->boolean('auto_assign_tables')->default(false)->after('ticketing_mode');
        });

        // Preserve existing events: seating_enabled=true were reserved-seating events.
        DB::table('events')->where('seating_enabled', true)->update(['ticketing_mode' => 'reserved']);

        Schema::table('seating_tables', function (Blueprint $table) {
            $table->string('shape', 8)->default('round')->after('name');   // round | rect
            $table->integer('pos_x')->default(0)->after('capacity');       // floorplan position
            $table->integer('pos_y')->default(0)->after('pos_x');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['ticketing_mode', 'auto_assign_tables']);
        });
        Schema::table('seating_tables', function (Blueprint $table) {
            $table->dropColumn(['shape', 'pos_x', 'pos_y']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Spatial layout: sections (and a "stage" block) are positioned on a
        // canvas so the seat map matches the real venue.
        Schema::table('seat_sections', function (Blueprint $table) {
            $table->integer('x')->default(20)->after('sort_order');       // canvas position (logical units)
            $table->integer('y')->default(20)->after('x');
            $table->integer('width')->nullable()->after('y');             // stage / GA footprint
            $table->integer('height')->nullable()->after('width');
            $table->string('row_label_start', 4)->default('A')->after('height'); // first row letter
        });
    }

    public function down(): void
    {
        Schema::table('seat_sections', function (Blueprint $table) {
            $table->dropColumn(['x', 'y', 'width', 'height', 'row_label_start']);
        });
    }
};

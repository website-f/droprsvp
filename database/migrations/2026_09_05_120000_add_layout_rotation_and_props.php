<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seat_sections', function (Blueprint $table) {
            $table->smallInteger('rotation')->default(0)->after('curve'); // 0-359 degrees
        });
        Schema::table('seating_tables', function (Blueprint $table) {
            $table->smallInteger('rotation')->default(0)->after('pos_y');
        });

        // Floorplan fixtures for the table-management editor — stage, entrance, buffet, etc.
        Schema::create('event_props', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 24)->default('custom');
            $table->string('label', 60)->nullable();
            $table->string('color', 20)->nullable();
            $table->integer('pos_x')->default(20);
            $table->integer('pos_y')->default(20);
            $table->integer('width')->default(160);
            $table->integer('height')->default(90);
            $table->smallInteger('rotation')->default(0);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_props');
        Schema::table('seat_sections', fn (Blueprint $table) => $table->dropColumn('rotation'));
        Schema::table('seating_tables', fn (Blueprint $table) => $table->dropColumn('rotation'));
    }
};

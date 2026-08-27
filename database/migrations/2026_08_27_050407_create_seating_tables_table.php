<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tables an event's attendees are seated at (gala / dinner style seating). Each
 * table has a capacity; tickets are assigned to a table (see tickets.seating_table_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seating_tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('capacity')->default(8);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seating_tables');
    }
};

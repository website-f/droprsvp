<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Whether this event uses a reserved-seating chart (vs general admission).
        Schema::table('events', function (Blueprint $table) {
            $table->boolean('seating_enabled')->default(false)->after('gallery');
        });

        // A priced area of the venue (VIP block, stalls, GA standing pit…). Each
        // section is backed by a ticket type so all the existing inventory /
        // checkout / refund machinery keeps working.
        Schema::create('seat_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_type_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('color', 20)->default('#6c63ff');
            $table->string('kind')->default('seated');   // seated (row/col grid) | ga (standing)
            $table->decimal('price', 10, 2)->default(0);
            $table->string('currency', 8)->default('MYR');
            $table->unsignedSmallInteger('rows')->nullable();     // seated
            $table->unsignedSmallInteger('cols')->nullable();     // seated
            $table->unsignedInteger('capacity')->nullable();      // ga
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Individual seats for seated sections. A seat's status is the source of
        // truth for reserved-seat availability.
        Schema::create('seats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seat_section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('row_label', 8);
            $table->unsignedSmallInteger('number');
            $table->string('label', 16);                 // e.g. "A12"
            $table->string('status')->default('available'); // available | held | sold
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['event_id', 'status']);
            $table->unique(['seat_section_id', 'row_label', 'number']);
        });

        // Reusable seating layouts an organizer can apply to another event.
        Schema::create('seat_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->json('data');                        // [{name,color,kind,price,rows,cols,capacity}]
            $table->timestamps();
        });

        // A ticket type can belong to a seat section (auto-managed) — keeps them
        // out of the manual GA ticket editor.
        Schema::table('ticket_types', function (Blueprint $table) {
            $table->foreignId('seat_section_id')->nullable()->after('event_id');
        });

        // Link an issued ticket / order line to its seat.
        Schema::table('tickets', function (Blueprint $table) {
            $table->foreignId('seat_section_id')->nullable()->after('ticket_type_id');
        });
        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('seat_section_id')->nullable()->after('ticket_type_id');
            $table->foreignId('seat_id')->nullable()->after('seat_section_id');
            $table->string('seat_label')->nullable()->after('seat_id');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['seat_section_id', 'seat_id', 'seat_label']);
        });
        Schema::table('tickets', fn (Blueprint $t) => $t->dropColumn('seat_section_id'));
        Schema::table('ticket_types', fn (Blueprint $t) => $t->dropColumn('seat_section_id'));
        Schema::dropIfExists('seat_templates');
        Schema::dropIfExists('seats');
        Schema::dropIfExists('seat_sections');
        Schema::table('events', fn (Blueprint $t) => $t->dropColumn('seating_enabled'));
    }
};

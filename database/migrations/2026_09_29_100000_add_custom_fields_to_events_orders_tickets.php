<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Organizer-defined questions on the checkout form ("which water?", "t-shirt
 * size", "dietary needs"), answered ONCE PER TICKET.
 *
 * Three columns because the data lives at three different moments:
 *
 *   events.custom_fields    the organizer's question definitions
 *   orders.custom_answers   what the buyer typed, captured at checkout — before
 *                           any ticket exists, since tickets are only issued
 *                           once payment settles
 *   tickets.custom_answers  copied onto each ticket at issuance, so check-in,
 *                           the attendee list and the ticket itself can show the
 *                           answer without walking back to the order
 *
 * JSON rather than tables: the shape is authored per event, read as a whole, and
 * never queried across events. A relational schema here would buy nothing and
 * cost two joins on the checkout page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            // [{ id, label, type, required, help, multiple, options:[{id,label,image}] }]
            $table->json('custom_fields')->nullable()->after('gallery');
        });

        Schema::table('orders', function (Blueprint $table) {
            // One entry per ticket in the order, in issuance order: [{ "<fieldId>": value }]
            $table->json('custom_answers')->nullable()->after('tax');
        });

        Schema::table('tickets', function (Blueprint $table) {
            // That ticket's own slice of the above: { "<fieldId>": value }
            $table->json('custom_answers')->nullable()->after('seat_label');
        });
    }

    public function down(): void
    {
        Schema::table('events', fn (Blueprint $t) => $t->dropColumn('custom_fields'));
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn('custom_answers'));
        Schema::table('tickets', fn (Blueprint $t) => $t->dropColumn('custom_answers'));
    }
};

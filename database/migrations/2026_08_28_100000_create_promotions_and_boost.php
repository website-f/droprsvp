<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            // While in the future, the event is "boosted" (featured + surfaced first).
            $table->timestamp('boosted_until')->nullable()->after('published_at');
        });

        // A paid promotion (organizer → platform) that boosts an event for N days.
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->unsignedSmallInteger('days');
            $table->string('status')->default('pending'); // pending | paid
            $table->string('payment_ref')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotions');
        Schema::table('events', fn (Blueprint $table) => $table->dropColumn('boosted_until'));
    }
};

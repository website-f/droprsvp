<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A payout of an organizer's net ticket revenue. `amount` is the net figure at
 * request time; the superadmin later marks it paid once the transfer is made.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // the organizer
            $table->string('reference')->unique();
            $table->decimal('amount', 10, 2);
            $table->char('currency', 3)->default('MYR');
            $table->string('status')->default('pending');   // pending | paid
            $table->string('method')->nullable();
            $table->string('note')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payouts');
    }
};

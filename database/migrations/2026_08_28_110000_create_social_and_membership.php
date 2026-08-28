<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Event discussion — questions from attendees + replies from the organizer.
        Schema::create('event_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('event_comments')->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();
            $table->index(['event_id', 'parent_id']);
        });

        // Premium membership on users.
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('premium_until')->nullable()->after('email_verified_at');
        });

        // Premium subscription payments (user → platform).
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
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
        Schema::dropIfExists('subscriptions');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('premium_until'));
        Schema::dropIfExists('event_comments');
    }
};

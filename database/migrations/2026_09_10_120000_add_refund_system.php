<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            // no_refunds | until_event | anytime
            $table->string('refund_policy')->default('until_event')->after('status');
            $table->string('refund_policy_note', 500)->nullable()->after('refund_policy');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('refunded_amount', 10, 2)->default(0)->after('total');
        });

        Schema::create('refund_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // the requester (buyer)
            $table->decimal('amount', 10, 2);                 // amount the buyer asked for
            $table->text('reason')->nullable();
            $table->string('status')->default('pending')->index(); // pending | approved | declined | cancelled
            $table->decimal('approved_amount', 10, 2)->nullable(); // what the organizer actually refunded
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();
            $table->index(['order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_requests');
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn('refunded_amount'));
        Schema::table('events', fn (Blueprint $t) => $t->dropColumn(['refund_policy', 'refund_policy_note']));
    }
};

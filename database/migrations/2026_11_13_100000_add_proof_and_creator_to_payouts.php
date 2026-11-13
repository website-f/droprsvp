<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payouts an admin pays by hand.
 *
 * proof       the transfer slip or screenshot, shown to the organizer
 * created_by  set when a superadmin raised the payout on the organizer's
 *             behalf (they had never pressed "Request payout"); null when the
 *             organizer requested it themselves
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payouts', function (Blueprint $table) {
            if (! Schema::hasColumn('payouts', 'proof')) {
                $table->string('proof', 2048)->nullable()->after('note');
                $table->foreignId('created_by')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('payouts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn('proof');
        });
    }
};

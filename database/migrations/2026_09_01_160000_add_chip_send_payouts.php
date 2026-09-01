<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Organizer bank details for automated payouts (CHIP Send).
        Schema::table('users', function (Blueprint $table) {
            $table->string('payout_bank_code')->nullable()->after('country');
            $table->string('payout_bank_account_number')->nullable()->after('payout_bank_code');
            $table->string('payout_bank_account_name')->nullable()->after('payout_bank_account_number');
            $table->unsignedBigInteger('chip_bank_account_id')->nullable()->after('payout_bank_account_name'); // CHIP Send bank_account id
        });

        // Track an automated (CHIP Send) payout instruction.
        Schema::table('payouts', function (Blueprint $table) {
            $table->unsignedBigInteger('chip_send_id')->nullable()->after('method'); // send_instruction id
            $table->string('chip_send_state')->nullable()->after('chip_send_id');     // received…completed…rejected
        });
    }

    public function down(): void
    {
        Schema::table('payouts', fn (Blueprint $t) => $t->dropColumn(['chip_send_id', 'chip_send_state']));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['payout_bank_code', 'payout_bank_account_number', 'payout_bank_account_name', 'chip_bank_account_id']));
    }
};

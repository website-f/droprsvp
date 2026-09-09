<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-organizer booking-fee override. NULL = this organizer is billed at the
        // global rate (Admin → Settings → Payments); a value here replaces it for
        // every new order on their events. Existing orders keep the fee they froze.
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('platform_fee_percent', 5, 2)->nullable()->after('chip_bank_account_id');
            $table->decimal('platform_fee_flat', 10, 2)->nullable()->after('platform_fee_percent');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['platform_fee_percent', 'platform_fee_flat']);
        });
    }
};

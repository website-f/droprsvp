<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A ledger of which sales have reached Google Analytics, and how.
 *
 * Two paths report a purchase — the buyer's browser on the confirmation page,
 * and the server catching any the browser could not — and they must never
 * both count the same sale. These columns are how they agree:
 *
 *   ga_claimed_at   the browser is about to send it (others keep off for a while)
 *   ga_reported_at  it has been sent, by...
 *   ga_reported_via ...'browser' or 'server'
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('ga_claimed_at')->nullable()->after('paid_at');
            $table->timestamp('ga_reported_at')->nullable()->after('ga_claimed_at');
            $table->string('ga_reported_via', 10)->nullable()->after('ga_reported_at');
            $table->index(['ga_reported_at', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['ga_reported_at', 'paid_at']);
            $table->dropColumn(['ga_claimed_at', 'ga_reported_at', 'ga_reported_via']);
        });
    }
};

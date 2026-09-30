<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record HOW each payment was made.
 *
 * CHIP already tells us — its dashboard shows the bank or card scheme next to
 * every purchase — but we only stored the amount, so the finance page could not
 * answer "which bank did this come through?".
 *
 * Nullable with no backfill: rows settled before this shipped genuinely do not
 * have the information locally, and inventing a value would be worse than an
 * empty cell. They can be reconciled from CHIP later if it ever matters.
 */
return new class extends Migration
{
    /** The three tables that receive money through a CHIP purchase. */
    private const TABLES = ['orders', 'promotions', 'subscriptions'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'payment_method')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) {
                $t->string('payment_method', 32)->nullable()->after('payment_ref');  // fpx | card | duitnow_qr | ewallet
                $t->string('payment_brand', 48)->nullable()->after('payment_method'); // maybank2u | visa | tng | …
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'payment_method')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn(['payment_method', 'payment_brand']));
            }
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let a promo code be advertised on the event page.
 *
 * Every code was effectively secret: the only way a buyer could learn one
 * existed was for the organizer to post it somewhere else. That is right for an
 * influencer or partner code, and wrong for a launch offer the organizer wants
 * everyone to see — and the platform had no way to express the difference.
 *
 * Defaults to FALSE on purpose. Codes that already exist were created on the
 * understanding that nobody would see them, so turning them all public in a
 * migration would leak private discounts onto live event pages.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('discount_codes') || Schema::hasColumn('discount_codes', 'is_public')) {
            return;
        }

        Schema::table('discount_codes', function (Blueprint $table) {
            $table->boolean('is_public')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('discount_codes') && Schema::hasColumn('discount_codes', 'is_public')) {
            Schema::table('discount_codes', fn (Blueprint $table) => $table->dropColumn('is_public'));
        }
    }
};

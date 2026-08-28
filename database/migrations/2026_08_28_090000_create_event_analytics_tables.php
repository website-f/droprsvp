<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-day impressions (event-page views) + clicks (checkout starts) for trend charts.
        Schema::create('event_daily_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->date('stat_date');
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->timestamps();
            $table->unique(['event_id', 'stat_date']);
        });

        // Buyer demographics captured at checkout (powers audience analytics).
        Schema::table('orders', function (Blueprint $table) {
            $table->string('buyer_gender')->nullable()->after('buyer_phone');
            $table->string('buyer_age_band')->nullable()->after('buyer_gender');
            $table->string('buyer_city')->nullable()->after('buyer_age_band');
            $table->string('buyer_source')->nullable()->after('buyer_city');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_daily_stats');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['buyer_gender', 'buyer_age_band', 'buyer_city', 'buyer_source']);
        });
    }
};

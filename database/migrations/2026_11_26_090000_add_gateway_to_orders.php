<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which gateway an order is paid through: 'chip' (Malaysia, ringgit) or
 * 'airwallex' (other countries, their own currency). Existing orders were all
 * CHIP (or the fake gateway in development), which null reads as.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('gateway', 20)->nullable()->after('currency');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('gateway');
        });
    }
};

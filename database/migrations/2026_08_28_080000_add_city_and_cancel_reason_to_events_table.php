<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            // City powers the SEO discovery URLs (/en-my/{city}/{category}).
            $table->string('city')->nullable()->after('venue_address');
            // Reason a superadmin cancelled the event (policy moderation).
            $table->text('cancelled_reason')->nullable()->after('status');
            $table->index('city');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex(['city']);
            $table->dropColumn(['city', 'cancelled_reason']);
        });
    }
};

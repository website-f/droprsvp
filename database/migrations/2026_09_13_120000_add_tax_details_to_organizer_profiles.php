<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizer_profiles', function (Blueprint $table) {
            // Shown on the receipts an organizer issues to their attendees.
            $table->string('tax_number')->nullable()->after('business_name');
            $table->string('business_address', 500)->nullable()->after('tax_number');
        });
    }

    public function down(): void
    {
        Schema::table('organizer_profiles', function (Blueprint $table) {
            $table->dropColumn(['tax_number', 'business_address']);
        });
    }
};

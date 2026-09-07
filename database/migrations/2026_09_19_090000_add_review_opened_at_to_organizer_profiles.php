<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizer_profiles', function (Blueprint $table) {
            // Set the first time a superadmin opens the application detail. While this
            // is null the applicant may still edit; once set, editing is locked.
            $table->timestamp('review_opened_at')->nullable()->after('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::table('organizer_profiles', function (Blueprint $table) {
            $table->dropColumn('review_opened_at');
        });
    }
};

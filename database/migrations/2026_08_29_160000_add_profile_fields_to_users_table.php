<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('email');
            $table->string('gender', 20)->nullable()->after('phone');
            $table->string('age_band', 20)->nullable()->after('gender');
            $table->string('city')->nullable()->after('age_band');
            $table->string('country')->nullable()->after('city');
            $table->timestamp('profile_completed_at')->nullable()->after('country');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone', 'gender', 'age_band', 'city', 'country', 'profile_completed_at']);
        });
    }
};

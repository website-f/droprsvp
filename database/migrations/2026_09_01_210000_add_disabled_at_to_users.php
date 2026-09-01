<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets an admin disable (suspend) an account without deleting it. A disabled user
 * can't sign in, and must be disabled before it can be deleted — a safety guard so
 * active accounts aren't removed by accident.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('disabled_at')->nullable()->after('profile_completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('disabled_at'));
    }
};

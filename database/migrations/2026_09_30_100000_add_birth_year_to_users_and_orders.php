<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ask for a birth YEAR instead of an age band.
 *
 * "25–34" is a band someone has to place themselves in, and it silently goes
 * stale — the answer is wrong the moment they have a birthday. A year is a fact
 * they know, types faster, and stays true.
 *
 * `age_band` is kept and DERIVED from the year (App\Support\Profile::bandFor),
 * so every existing row, the analytics that group by band and the admin filters
 * all keep working. Dropping it would have meant rewriting reporting for a
 * change to one form field.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->smallInteger('birth_year')->nullable()->after('age_band');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->smallInteger('buyer_birth_year')->nullable()->after('buyer_age_band');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('birth_year'));
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn('buyer_birth_year'));
    }
};

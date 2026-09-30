<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Copy the phone an organizer gave on their application onto their account.
 *
 * The application form wrote only to organizer_profiles.phone, while the admin
 * user page, the user CSV export and the admin search all read users.phone. So
 * an organizer who plainly entered a number showed up as "Phone —".
 *
 * Only fills a gap: an account that already has a number keeps it, because
 * that one was set later and deliberately.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('organizer_profiles')
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->orderBy('id')
            ->select('user_id', 'phone')
            ->chunk(200, function ($profiles) {
                foreach ($profiles as $profile) {
                    DB::table('users')
                        ->where('id', $profile->user_id)
                        ->where(fn ($q) => $q->whereNull('phone')->orWhere('phone', ''))
                        ->update(['phone' => $profile->phone]);
                }
            });
    }

    public function down(): void
    {
        // Irreversible by design: we cannot tell a backfilled number from one
        // the person typed in themselves afterwards, and guessing would delete
        // real data.
    }
};

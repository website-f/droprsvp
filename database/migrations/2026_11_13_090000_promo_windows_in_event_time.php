<?php

use App\Models\DiscountCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Promo windows picked as dates were stored as midnight UTC: a code "ending
 * 14 Nov" in Kuala Lumpur stopped at 8:00 AM on the 14th. Move every such
 * window to whole days in its event's timezone — 12:00 AM on the first day,
 * 11:59:59 PM on the last — the way new saves store them.
 *
 * Only exact UTC midnights are touched; anything else was not a picked date.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('discount_codes')
            ->join('events', 'events.id', '=', 'discount_codes.event_id')
            ->where(fn ($q) => $q->whereNotNull('discount_codes.starts_at')->orWhereNotNull('discount_codes.ends_at'))
            ->select(['discount_codes.id', 'discount_codes.starts_at', 'discount_codes.ends_at', 'events.timezone'])
            ->orderBy('discount_codes.id')
            ->each(function (object $code) {
                $timezone = $code->timezone ?: config('app.display_timezone', 'Asia/Kuala_Lumpur');
                $changes = [];

                foreach (['starts_at' => 'startOfDayIn', 'ends_at' => 'endOfDayIn'] as $column => $convert) {
                    if ($code->{$column} && str_ends_with((string) $code->{$column}, '00:00:00')) {
                        $changes[$column] = DiscountCode::$convert(substr((string) $code->{$column}, 0, 10), $timezone)?->format('Y-m-d H:i:s');
                    }
                }

                if ($changes) {
                    DB::table('discount_codes')->where('id', $code->id)->update($changes);
                }
            });
    }

    public function down(): void
    {
        // The old values were the bug; nothing to restore.
    }
};

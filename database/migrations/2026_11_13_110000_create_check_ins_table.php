<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row per ticket per day it was checked in.
 *
 * A ticket used to hold a single check-in (status + checked_in_at), so a
 * week-long event — a volunteer programme with 800 people scanning in every
 * morning — reported everyone as "already checked in" from day two on, and
 * nobody could say who came on which day. With `events.daily_checkin` on, the
 * door checks people in once per day, and attendance is read from here.
 *
 * The unique (ticket_id, day) key is what makes two phones scanning the same
 * pass at the same moment record one check-in, not two. Every check-in is
 * written here (daily or not), so each one is on record with who scanned it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('check_ins')) {
            Schema::create('check_ins', function (Blueprint $table) {
                $table->id();
                $table->foreignId('event_id')->constrained()->cascadeOnDelete();
                $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
                $table->date('day');                 // in the event's own timezone
                $table->timestamp('checked_in_at');
                $table->foreignId('checked_in_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('via', 16)->default('scan'); // scan | manual
                $table->timestamps();

                $table->unique(['ticket_id', 'day']);
                $table->index(['event_id', 'day']);
            });
        }

        if (! Schema::hasColumn('events', 'daily_checkin')) {
            Schema::table('events', function (Blueprint $table) {
                $table->boolean('daily_checkin')->default(false)->after('show_reviews');
            });
        }

        // Existing check-ins become the first rows, on the day they happened.
        DB::table('tickets')
            ->join('events', 'events.id', '=', 'tickets.event_id')
            ->where('tickets.status', 'checked_in')
            ->whereNotNull('tickets.checked_in_at')
            ->select(['tickets.id', 'tickets.event_id', 'tickets.checked_in_at', 'tickets.checked_in_by', 'events.timezone'])
            ->orderBy('tickets.id')
            ->chunk(500, function ($rows) {
                DB::table('check_ins')->insertOrIgnore($rows->map(fn ($r) => [
                    'event_id' => $r->event_id,
                    'ticket_id' => $r->id,
                    'day' => Carbon::parse($r->checked_in_at, 'UTC')->setTimezone($r->timezone ?: config('app.display_timezone', 'Asia/Kuala_Lumpur'))->toDateString(),
                    'checked_in_at' => $r->checked_in_at,
                    'checked_in_by' => $r->checked_in_by,
                    'via' => 'scan',
                    'created_at' => now(),
                    'updated_at' => now(),
                ])->all());
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('check_ins');

        if (Schema::hasColumn('events', 'daily_checkin')) {
            Schema::table('events', fn (Blueprint $table) => $table->dropColumn('daily_checkin'));
        }
    }
};

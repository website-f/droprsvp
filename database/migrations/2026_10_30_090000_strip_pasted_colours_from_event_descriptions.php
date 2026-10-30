<?php

use App\Support\HtmlSanitizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Take the colours that came in with pasted text out of existing event
 * descriptions — text copied from the site in dark mode carried its
 * near-white and showed white on white on the light event page. New saves
 * are cleaned by the organizer's event form (HtmlSanitizer::withoutColours).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('events')
            ->where(fn ($q) => $q->where('description', 'like', '%color%')->orWhere('description', 'like', '%background%'))
            ->select(['id', 'description'])
            ->orderBy('id')
            ->each(function (object $event) {
                $cleaned = HtmlSanitizer::withoutColours($event->description);

                if ($cleaned !== $event->description) {
                    DB::table('events')->where('id', $event->id)->update(['description' => $cleaned]);
                }
            });
    }

    public function down(): void
    {
        // The colours were never wanted; nothing to put back.
    }
};

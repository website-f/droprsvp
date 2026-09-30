<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Support\Ics;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class CalendarController extends Controller
{
    /** Download an event as a .ics file (Apple Calendar / Outlook / anything). */
    public function event(Event $event): Response
    {
        abort_unless($event->status === 'published', 404);

        $filename = Str::slug($event->title ?: 'event').'.ics';

        return response(Ics::forEvent($event), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}

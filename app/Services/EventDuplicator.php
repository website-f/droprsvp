<?php

namespace App\Services;

use App\Models\Event;
use App\Models\SeatSection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Clone an event into a fresh draft — its ticket types, sessions, seating layout
 * and props, but none of its sales (orders, tickets, seats are reset to open).
 * Handy for recurring events: duplicate, change the date, publish.
 */
class EventDuplicator
{
    public function duplicate(Event $event): Event
    {
        $event->loadMissing(['ticketTypes', 'sessions', 'seatSections', 'seatingTables', 'props', 'seats']);

        return DB::transaction(function () use ($event) {
            // 1. The event itself — as an unpublished draft, sales history dropped.
            $copy = $event->replicate([
                'published_at', 'boosted_until', 'cancelled_reason',
                'appeal_status', 'appeal_reason', 'appeal_attachments', 'appealed_at',
            ]);
            $copy->title = Str::limit($event->title, 240, '').' (Copy)';
            $copy->status = 'draft';
            $copy->published_at = null;
            $copy->boosted_until = null;
            $copy->appeal_status = null;
            $copy->slug = $this->uniqueSlug($event->title.' copy');
            $copy->save();

            // 2. Seat sections first, without their ticket-type link (created next).
            $sectionMap = [];
            foreach ($event->seatSections as $section) {
                $new = $section->replicate(['ticket_type_id']);
                $new->event_id = $copy->id;
                $new->ticket_type_id = null;
                $new->save();
                $sectionMap[$section->id] = $new->id;
            }

            // 3. Ticket types, with sold counts reset and section links remapped.
            $ticketTypeMap = [];
            foreach ($event->ticketTypes as $tt) {
                $new = $tt->replicate();
                $new->event_id = $copy->id;
                $new->sold = 0;
                $new->seat_section_id = $tt->seat_section_id ? ($sectionMap[$tt->seat_section_id] ?? null) : null;
                $new->save();
                $ticketTypeMap[$tt->id] = $new->id;
            }

            // 4. Backfill each new section's ticket_type_id now that both exist.
            foreach ($event->seatSections as $section) {
                if ($section->ticket_type_id && isset($sectionMap[$section->id])) {
                    SeatSection::whereKey($sectionMap[$section->id])
                        ->update(['ticket_type_id' => $ticketTypeMap[$section->ticket_type_id] ?? null]);
                }
            }

            // 5. Seats — remapped to the new sections and freed for sale.
            foreach ($event->seats as $seat) {
                $new = $seat->replicate(['order_id']);
                $new->event_id = $copy->id;
                $new->seat_section_id = $seat->seat_section_id ? ($sectionMap[$seat->seat_section_id] ?? null) : null;
                $new->order_id = null;
                $new->status = 'available';
                $new->save();
            }

            // 6. Tables, props and sessions carry over verbatim.
            foreach ($event->seatingTables as $table) {
                $new = $table->replicate();
                $new->event_id = $copy->id;
                $new->save();
            }
            foreach ($event->props as $prop) {
                $new = $prop->replicate();
                $new->event_id = $copy->id;
                $new->save();
            }
            foreach ($event->sessions as $session) {
                $new = $session->replicate();
                $new->event_id = $copy->id;
                $new->save();
            }

            return $copy;
        });
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'event';
        $slug = $base;
        $i = 2;
        while (Event::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}

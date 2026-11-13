<?php

namespace App\Services;

use App\Models\CheckIn;
use App\Models\Event;
use App\Models\Ticket;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Checking a ticket in at the door, once — or, for an event with daily
 * check-in on, once per day.
 *
 * Both are race-safe for a busy door with several phones scanning:
 *  - once: the ticket is claimed with a conditional update (only a ticket that
 *    is still `valid` flips to `checked_in`), so two scans of one pass at the
 *    same instant admit it once and report the second as already in;
 *  - daily: the check_ins row is the claim, and its unique (ticket, day) key
 *    lets exactly one of two simultaneous inserts through.
 *
 * Only a live ticket (valid or checked in) gets in. Refunded, void and
 * cancelled ones are turned away — cancelled used to slip through, because
 * the door only ever rejected `void` and `refunded`.
 */
class CheckInService
{
    /** Today's date where the event is, which is the "day" a check-in counts for. */
    public function today(Event $event): string
    {
        return CarbonImmutable::now($event->timezone ?: config('app.display_timezone', 'Asia/Kuala_Lumpur'))->toDateString();
    }

    /**
     * @return array{result: 'ok'|'already'|'invalid', message: string}
     */
    public function checkIn(Event $event, Ticket $ticket, ?int $userId, string $via = 'scan'): array
    {
        if (! in_array($ticket->status, ['valid', 'checked_in'], true)) {
            return ['result' => 'invalid', 'message' => 'This ticket is '.$ticket->status.'.'];
        }

        $day = $this->today($event);
        $now = now();

        if (! $event->daily_checkin) {
            $claimed = Ticket::whereKey($ticket->id)->where('status', 'valid')->update([
                'status' => 'checked_in',
                'checked_in_at' => $now,
                'checked_in_by' => $userId,
                'check_in_log' => json_encode($this->log($ticket, 'in', $userId)),
            ]);

            if (! $claimed) {
                $ticket->refresh();

                return ['result' => 'already', 'message' => 'Already checked in'.$this->at($event, $ticket->checked_in_at, true).'.'];
            }

            $this->record($event, $ticket, $day, $now, $userId, $via);

            return ['result' => 'ok', 'message' => 'Checked in.'];
        }

        try {
            DB::transaction(function () use ($event, $ticket, $day, $now, $userId, $via) {
                CheckIn::create([
                    'event_id' => $event->id, 'ticket_id' => $ticket->id, 'day' => $day,
                    'checked_in_at' => $now, 'checked_in_by' => $userId, 'via' => $via,
                ]);

                $ticket->update([
                    'status' => 'checked_in',
                    'checked_in_at' => $now,
                    'checked_in_by' => $userId,
                    'check_in_log' => $this->log($ticket, 'in', $userId),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            $first = CheckIn::where('ticket_id', $ticket->id)->where('day', $day)->value('checked_in_at');

            return ['result' => 'already', 'message' => 'Already checked in today'.$this->at($event, $first ? CarbonImmutable::parse($first) : null).'.'];
        }

        $days = CheckIn::where('ticket_id', $ticket->id)->count();

        return ['result' => 'ok', 'message' => 'Checked in for '.CarbonImmutable::parse($day)->format('D, j M').($days > 1 ? " · day {$days} attended" : '').'.'];
    }

    /**
     * Take back the latest check-in: today's for a daily event, the only one
     * otherwise. The ticket stays "checked in" if it still has other days.
     *
     * @return array{result: 'ok'|'invalid', message: string}
     */
    public function undo(Event $event, Ticket $ticket, int $userId): array
    {
        if (! in_array($ticket->status, ['valid', 'checked_in'], true)) {
            return ['result' => 'invalid', 'message' => 'This ticket is '.$ticket->status.'.'];
        }

        $day = $this->today($event);

        if ($event->daily_checkin) {
            $removed = CheckIn::where('ticket_id', $ticket->id)->where('day', $day)->delete();

            if (! $removed) {
                return ['result' => 'invalid', 'message' => 'This ticket is not checked in today, so there is nothing to undo.'];
            }
        } elseif ($ticket->status !== 'checked_in') {
            return ['result' => 'invalid', 'message' => 'This ticket is not checked in, so there is nothing to undo.'];
        } else {
            CheckIn::where('ticket_id', $ticket->id)->delete();
        }

        $latest = CheckIn::where('ticket_id', $ticket->id)->orderByDesc('checked_in_at')->first();

        $ticket->update([
            'status' => $latest ? 'checked_in' : 'valid',
            'checked_in_at' => $latest?->checked_in_at,
            'checked_in_by' => $latest?->checked_in_by,
            'check_in_log' => $this->log($ticket, 'undo', $userId),
        ]);

        return ['result' => 'ok', 'message' => 'Check-in undone.'];
    }

    /** Whether the ticket is in for the day that counts: today on a daily event, ever otherwise. */
    public function isIn(Event $event, Ticket $ticket): bool
    {
        if (! $event->daily_checkin) {
            return $ticket->status === 'checked_in';
        }

        $today = $this->today($event);

        return $ticket->relationLoaded('checkIns')
            ? $ticket->checkIns->contains(fn (CheckIn $c) => substr((string) $c->day, 0, 10) === $today)
            : $ticket->checkIns()->where('day', $today)->exists();
    }

    private function record(Event $event, Ticket $ticket, string $day, $at, ?int $userId, string $via): void
    {
        CheckIn::insertOrIgnore([[
            'event_id' => $event->id, 'ticket_id' => $ticket->id, 'day' => $day,
            'checked_in_at' => $at, 'checked_in_by' => $userId, 'via' => $via,
            'created_at' => now(), 'updated_at' => now(),
        ]]);
    }

    /**
     * The ticket's own short history, kept for the detail view (last 20).
     *
     * @return array<int, array{action: string, at: string, by: ?int}>
     */
    private function log(Ticket $ticket, string $action, ?int $userId): array
    {
        $log = $ticket->check_in_log ?? [];
        $log[] = ['action' => $action, 'at' => now()->toIso8601String(), 'by' => $userId];

        return array_slice($log, -20);
    }

    private function at(Event $event, $when, bool $withDay = false): string
    {
        if (! $when) {
            return '';
        }

        $local = CarbonImmutable::parse($when)->setTimezone($event->timezone ?: config('app.display_timezone', 'Asia/Kuala_Lumpur'));

        return $withDay && ! $local->isSameDay(CarbonImmutable::now($local->timezone))
            ? ' on '.$local->format('D, j M').' at '.$local->format('g:i A')
            : ' at '.$local->format('g:i A');
    }
}

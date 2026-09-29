<?php

namespace App\Console\Commands;

use App\Models\Event;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class FixEventTimezones extends Command
{
    protected $signature = 'events:fix-timezones {--dry-run : Report what would change without saving}';

    protected $description = 'Re-interpret event and session datetimes stored as local time but labelled UTC';

    /**
     * Every event saved before the timezone fix holds the organizer's LOCAL time
     * labelled as UTC, so the public page adds the offset again and shows a 3pm
     * session at 11pm. This shifts those rows back by the event's own offset.
     *
     * RUN ONCE. There is no marker distinguishing a corrected row from an
     * uncorrected one, so a second run would shift everything a second time.
     * --dry-run first, and take a database backup.
     *
     * Events already in UTC (or on a UTC-equivalent timezone) are unaffected,
     * because their offset is zero.
     */
    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $events = 0;
        $sessions = 0;

        Event::with('sessions')->chunkById(100, function ($chunk) use (&$events, &$sessions, $dry) {
            foreach ($chunk as $event) {
                $shifted = false;

                foreach ($event->sessions as $session) {
                    foreach (['starts_at', 'ends_at'] as $field) {
                        if (! $session->{$field}) {
                            continue;
                        }

                        $corrected = $this->reinterpret($session->{$field}, $event->timezone);

                        if ($dry) {
                            $this->line(sprintf(
                                '  %s · session %d %s: %s -> %s',
                                $event->slug,
                                $session->id,
                                $field,
                                $session->{$field}->format('Y-m-d H:i'),
                                $corrected->format('Y-m-d H:i'),
                            ));
                        } else {
                            $session->{$field} = $corrected;
                        }

                        $shifted = true;
                    }

                    if (! $dry && $session->isDirty()) {
                        $session->save();
                        $sessions++;
                    }
                }

                // The event's own window is the min/max of its sessions, so it has
                // to move with them or the two disagree.
                foreach (['starts_at', 'ends_at'] as $field) {
                    if ($event->{$field}) {
                        $corrected = $this->reinterpret($event->{$field}, $event->timezone);

                        if (! $dry) {
                            $event->{$field} = $corrected;
                        }

                        $shifted = true;
                    }
                }

                if ($shifted) {
                    if (! $dry && $event->isDirty()) {
                        $event->save();
                    }
                    $events++;
                }
            }
        });

        $this->info(($dry ? 'Would correct' : 'Corrected')." {$events} event(s) and {$sessions} session(s).");

        if ($dry) {
            $this->warn('Dry run — nothing written. This command must be run ONCE only.');
        }

        return self::SUCCESS;
    }

    /**
     * Read the stored wall-clock time as if it had been in $timezone all along,
     * and return the real UTC instant.
     *
     * "2026-10-10 15:00 UTC" stored for an Asia/Kuala_Lumpur event was really
     * 3pm local, i.e. 07:00 UTC.
     *
     * Typed to DateTimeInterface because the models cast to CarbonImmutable,
     * which is not an Illuminate\Support\Carbon.
     */
    private function reinterpret(\DateTimeInterface $stored, ?string $timezone): Carbon
    {
        return Carbon::createFromFormat(
            'Y-m-d H:i:s',
            $stored->format('Y-m-d H:i:s'),
            $timezone ?: 'UTC',
        )->utc();
    }
}

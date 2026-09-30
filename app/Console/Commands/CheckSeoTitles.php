<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\User;
use App\Support\SeoTemplate;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * What title and description each event and organizer page will ACTUALLY emit,
 * and why.
 *
 * Written to settle a specific question: "the template isn't applying — does it
 * only work for new events?" It does not; the house template is a fallback
 * applied at render time, so it covers every event ever created. But there are
 * two things that legitimately override it — a per-event SEO entry, and an
 * edited house template — and from the outside all three cases look the same.
 *
 * So this prints the answer per record, with the reason:
 *
 *   php artisan seo:check                 every event, plus the organizers
 *   php artisan seo:check --overrides     only the ones NOT using the template
 *   php artisan seo:check --slug=my-event one event, in full
 */
class CheckSeoTitles extends Command
{
    protected $signature = 'seo:check
        {--slug= : Inspect a single event by slug}
        {--overrides : Only list records whose own SEO entry overrides the house template}
        {--limit=40 : How many events to list}';

    protected $description = 'Show the title and description each event/organizer page will emit, and where it came from';

    public function handle(): int
    {
        $this->line('');
        $this->components->info('House templates currently in force');

        foreach (SeoTemplate::allHouse() as $key => $template) {
            $isDefault = $template === (SeoTemplate::DEFAULTS[$key] ?? null);
            $this->line(sprintf('  %-22s %s', $key, $template));
            $this->line(sprintf('  %-22s %s', '', $isDefault ? '(shipped default)' : '(EDITED in Admin → SEO)'));
        }

        $this->line('');

        return $this->option('slug') ? $this->one() : $this->many();
    }

    private function one(): int
    {
        $event = Event::with(['seo', 'category', 'user'])->where('slug', $this->option('slug'))->first();

        if (! $event) {
            $this->components->error('No event with slug '.$this->option('slug'));

            return self::FAILURE;
        }

        $this->components->info($event->title);
        $this->line('  URL          '.url('/en-my/e/'.$event->slug).'/');
        $this->line('');

        $override = $event->seo?->seo_title;
        $this->line('  TITLE');
        $this->line('    emits     '.($this->titleFor($event) ?: '(none)'));
        $this->line('    source    '.($override ? 'per-event override: "'.$override.'"' : 'house template'));
        $this->line('');

        $descOverride = $event->seo?->meta_description;
        $this->line('  DESCRIPTION');
        $this->line('    emits     '.Str::limit($this->descriptionFor($event) ?: '(none)', 150));
        $this->line('    source    '.($descOverride ? 'per-event override' : 'house template'));
        $this->line('');

        if ($event->seo?->canonical_url) {
            $this->components->warn('  A canonical URL is set by hand: '.$event->seo->canonical_url);
        }

        $this->line('  Token values for this event:');

        foreach (SeoTemplate::values($event) as $token => $value) {
            $this->line(sprintf('    %-16s %s', $token, $value === '' ? '(empty — dropped from the template)' : $value));
        }

        return self::SUCCESS;
    }

    private function many(): int
    {
        $onlyOverrides = (bool) $this->option('overrides');

        $events = Event::with(['seo', 'category', 'user'])
            ->latest()
            ->limit((int) $this->option('limit'))
            ->get()
            ->filter(fn (Event $e) => ! $onlyOverrides || $e->seo?->seo_title || $e->seo?->meta_description);

        $this->components->info($onlyOverrides ? 'Events NOT using the house template' : 'Events');

        if ($events->isEmpty()) {
            $this->line('  '.($onlyOverrides
                ? 'None — every event is using the house template.'
                : 'No events yet.'));
        }

        foreach ($events as $event) {
            $source = $event->seo?->seo_title ? 'OVERRIDE' : 'template';
            $this->line(sprintf('  [%s] %s', $source, $this->titleFor($event)));
            $this->line('            /en-my/e/'.$event->slug.'/');
        }

        $this->line('');
        $this->components->info('Organizers');

        foreach (User::role('organizer')->with('organizerProfile')->limit(20)->get() as $organizer) {
            $this->line('  [template] '.SeoTemplate::forOrganizer('organizer_title', $organizer));
            $this->line('             /en-my/o/'.($organizer->slug ?: '(no slug yet)').'/');
        }

        $this->line('');
        $this->components->warn('If a page in the browser disagrees with what is printed here, the deployed code is older than this checkout — run the deploy and `php artisan optimize:clear`.');

        return self::SUCCESS;
    }

    /** Exactly the fallback chain Public\EventController uses. */
    private function titleFor(Event $event): string
    {
        return SeoTemplate::render($event->seo?->seo_title, $event)
            ?: (SeoTemplate::forEvent('event_title', $event) ?: $event->title);
    }

    private function descriptionFor(Event $event): string
    {
        $plain = Str::limit(trim(strip_tags((string) $event->description)) ?: (string) $event->subtitle, 155);

        return SeoTemplate::render($event->seo?->meta_description, $event)
            ?: (SeoTemplate::forEvent('event_description', $event) ?: $plain);
    }
}

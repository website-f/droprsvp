<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventCategory;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A ceiling on the MySQL queries one anonymous page view may cost.
 *
 * Added after the sister site was suspended by its host for MySQL overload. The
 * failure mode there is not a leak — it is per-request cost multiplied by
 * traffic, and public pages are exactly where an N+1 does the most damage
 * because crawlers hit them hardest. The browse page was doing one Order query
 * per event card (13 on a 12-event page) purely to draw three attendee names;
 * EventFaces now batches that into one.
 *
 * Only CONTENT queries are counted: the session and cache tables are excluded
 * because their cost depends on the driver, not on this code. That keeps the
 * numbers stable whether production runs database or file drivers, while still
 * catching a new N+1 — which is what these budgets exist for.
 *
 * A budget here is a smoke alarm, not a target. If a change genuinely needs more
 * queries, raise the number and say why in the commit.
 */
class QueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    /** Tables whose traffic is a driver choice, not a property of our code. */
    private const INFRASTRUCTURE = ['sessions', 'cache', 'cache_locks', 'jobs'];

    /** Deliberately more events than fit on a page, so an N+1 shows up. */
    private function seedData(int $events = 30): void
    {
        $host = User::factory()->create(['slug' => 'host-one', 'name' => 'Host One']);
        $category = EventCategory::firstOrCreate(['slug' => 'music'], ['name' => 'Music', 'sort_order' => 1]);

        for ($i = 0; $i < $events; $i++) {
            $event = Event::create([
                'user_id' => $host->id, 'category_id' => $category->id,
                'title' => 'Event '.$i, 'slug' => 'event-'.$i,
                'description' => 'Something happening.', 'status' => 'published',
                'visibility' => 'public', 'city' => 'Kuala Lumpur',
                'venue_name' => 'Venue '.$i, 'timezone' => 'Asia/Kuala_Lumpur',
                'starts_at' => now()->addDays($i + 1),
            ]);
            TicketType::create([
                'event_id' => $event->id, 'name' => 'GA', 'kind' => 'paid',
                'price' => 50, 'currency' => 'MYR', 'quantity' => 100, 'is_active' => true,
            ]);
        }
    }

    /** @return array{0:int,1:array<string,int>} content query count, and a per-table tally */
    private function measure(string $path): array
    {
        $tables = [];
        $count = 0;

        DB::listen(function ($query) use (&$tables, &$count) {
            if (! preg_match('~(?:from|into|update)\s+"?([a-z_]+)"?~i', $query->sql, $m)) {
                return;
            }
            if (in_array($m[1], self::INFRASTRUCTURE, true)) {
                return;
            }
            $tables[$m[1]] = ($tables[$m[1]] ?? 0) + 1;
            $count++;
        });

        $this->get($path)->assertOk();

        DB::getEventDispatcher()->forget(QueryExecuted::class);
        arsort($tables);

        return [$count, $tables];
    }

    public function test_public_pages_stay_within_their_query_budget(): void
    {
        $this->seedData();

        // path => max CONTENT queries for one anonymous request
        $budgets = [
            '/en-my/' => 20,
            '/en-my/all/' => 14,
            '/en-my/kuala-lumpur/' => 14,
            '/en-my/e/event-0/' => 24,
            '/en-my/o/host-one/' => 18,
            '/en-my/blog/' => 8,
            '/en-my/help/' => 4,
            '/sitemap.xml' => 10,
        ];

        $over = [];

        foreach ($budgets as $path => $budget) {
            [$count, $tables] = $this->measure($path);

            if ($count > $budget) {
                $detail = implode(', ', array_map(
                    fn ($t, $n) => "{$t}x{$n}",
                    array_keys($tables),
                    $tables,
                ));
                $over[] = "  {$path}: {$count} queries, budget {$budget} — {$detail}";
            }
        }

        $this->assertSame([], $over, implode("\n", [
            'These pages now cost more MySQL queries than their budget allows.',
            'The usual cause is an N+1: a query inside a per-row mapper. Batch it',
            'the way App\Support\EventFaces does, or raise the budget and say why.',
            '',
            ...$over,
            '',
        ]));
    }

    /**
     * The specific regression that prompted all of this: attendee "faces" were
     * fetched one query per card. The count must not scale with page size.
     */
    public function test_the_browse_page_does_not_query_orders_once_per_card(): void
    {
        $this->seedData(30);

        [, $tables] = $this->measure('/en-my/all/');

        $this->assertLessThanOrEqual(
            3,
            $tables['orders'] ?? 0,
            'The browse page is querying `orders` once per event card again — batch it through App\Support\EventFaces.',
        );
    }
}

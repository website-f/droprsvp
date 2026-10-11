<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "How many Ticket A, how many Ticket B" on the attendee list, so an organizer
 * selling more than one type does not have to filter to each in turn and count.
 */
class AttendeesByTicketTypeTest extends TestCase
{
    use RefreshDatabase;

    private User $host;

    private Event $event;

    private TicketType $a;

    private TicketType $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = $this->organizer();
        $this->event = Event::create([
            'user_id' => $this->host->id, 'title' => 'Gala', 'slug' => 'gala',
            'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
        ]);

        $this->a = $this->event->ticketTypes()->create(['name' => 'Ticket A', 'kind' => 'paid', 'price' => 50, 'sort_order' => 1, 'is_active' => true]);
        $this->b = $this->event->ticketTypes()->create(['name' => 'Ticket B', 'kind' => 'paid', 'price' => 80, 'sort_order' => 2, 'is_active' => true]);
    }

    private function admit(?TicketType $type, int $count = 1, string $status = 'valid'): void
    {
        $order = Order::create([
            'reference' => 'T-'.strtoupper(substr(uniqid(), -6)), 'event_id' => $this->event->id,
            'status' => 'paid', 'total' => 0, 'buyer_name' => 'Buyer', 'buyer_email' => 'b@x.com',
        ]);

        foreach (range(1, $count) as $ignored) {
            $order->tickets()->create([
                'event_id' => $this->event->id, 'ticket_type_id' => $type?->id,
                'attendee_name' => 'Ali', 'status' => $status,
            ]);
        }
    }

    private function stats(array $query = []): array
    {
        $response = $this->actingAs($this->host)
            ->get(route('host.events.attendees', $this->event).'?'.http_build_query($query))
            ->assertOk();

        return $response->viewData('page')['props']['stats'];
    }

    public function test_each_type_is_counted_without_filtering_to_it(): void
    {
        $this->admit($this->a, 5);
        $this->admit($this->b, 2);

        $stats = $this->stats();

        $this->assertSame(7, $stats['total']);
        $this->assertSame(
            [
                ['id' => $this->a->id, 'name' => 'Ticket A', 'total' => 5, 'checked_in' => 0],
                ['id' => $this->b->id, 'name' => 'Ticket B', 'total' => 2, 'checked_in' => 0],
            ],
            $stats['by_type'],
        );
    }

    public function test_the_split_says_how_many_of_each_have_arrived(): void
    {
        $this->admit($this->a, 3, 'checked_in');
        $this->admit($this->a, 1);
        $this->admit($this->b, 2, 'checked_in');

        $stats = $this->stats();

        $this->assertSame([4, 3], [$stats['by_type'][0]['total'], $stats['by_type'][0]['checked_in']]);
        $this->assertSame([2, 2], [$stats['by_type'][1]['total'], $stats['by_type'][1]['checked_in']]);
    }

    public function test_the_split_adds_up_to_the_admissions_figure(): void
    {
        $this->admit($this->a, 4);
        $this->admit($this->b, 3);
        // Refunded and void tickets admit nobody, so they count nowhere.
        $this->admit($this->a, 2, 'refunded');
        $this->admit($this->b, 1, 'void');

        $stats = $this->stats();

        $this->assertSame($stats['total'], array_sum(array_column($stats['by_type'], 'total')));
        $this->assertSame(7, $stats['total']);
    }

    public function test_a_type_nobody_bought_still_shows_as_zero(): void
    {
        $this->admit($this->a, 2);

        $stats = $this->stats();

        $this->assertSame(['Ticket A', 'Ticket B'], array_column($stats['by_type'], 'name'));
        $this->assertSame(0, $stats['by_type'][1]['total']);
    }

    public function test_tickets_whose_type_was_deleted_are_still_counted(): void
    {
        $this->admit($this->a, 2);
        $this->admit($this->b, 3);
        // The column is nullOnDelete, so these tickets keep admitting someone.
        $this->b->delete();

        $stats = $this->stats();

        $this->assertSame(5, $stats['total']);
        $this->assertSame(['Ticket A', 'Other'], array_column($stats['by_type'], 'name'));
        $this->assertSame(3, $stats['by_type'][1]['total']);
        $this->assertNull($stats['by_type'][1]['id']);
        $this->assertSame($stats['total'], array_sum(array_column($stats['by_type'], 'total')));
    }

    public function test_the_split_stays_whole_while_the_list_is_filtered(): void
    {
        $this->admit($this->a, 5);
        $this->admit($this->b, 2);

        // Filtering to one type narrows the list, not the split — the whole
        // point is to read both numbers at once.
        $stats = $this->stats(['type' => (string) $this->a->id]);

        $this->assertSame(5, $stats['by_type'][0]['total']);
        $this->assertSame(2, $stats['by_type'][1]['total']);
    }

    public function test_checking_someone_in_updates_their_types_count(): void
    {
        $this->admit($this->a, 2);
        $this->admit($this->b, 1);
        $ticket = Ticket::where('ticket_type_id', $this->b->id)->firstOrFail();

        $this->actingAs($this->host)
            ->postJson(route('host.events.attendees.scan', $this->event), ['token' => $ticket->qr_token, 'auto' => true])
            ->assertOk()
            ->assertJson(['result' => 'ok', 'stats' => [
                'checked_in' => 1,
                'by_type' => [
                    ['id' => $this->a->id, 'name' => 'Ticket A', 'total' => 2, 'checked_in' => 0],
                    ['id' => $this->b->id, 'name' => 'Ticket B', 'total' => 1, 'checked_in' => 1],
                ],
            ]]);
    }

    public function test_one_ticket_type_still_reports_its_own_split(): void
    {
        // The page hides a split of one, but the payload stays consistent.
        $this->b->delete();
        $this->admit($this->a, 3);

        $stats = $this->stats();

        $this->assertCount(1, $stats['by_type']);
        $this->assertSame(3, $stats['by_type'][0]['total']);
    }

    public function test_someone_elses_event_is_still_off_limits(): void
    {
        $this->actingAs($this->organizer())
            ->get(route('host.events.attendees', $this->event))
            ->assertForbidden();
    }
}

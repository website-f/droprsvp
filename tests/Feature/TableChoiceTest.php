<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Order;
use App\Models\SeatingTable;
use App\Models\TicketType;
use App\Services\CheckoutService;
use App\Services\TableAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Buyers choosing their own table at checkout (every buyer or only the first
 * N orders), tables reused per session, and a cheaper price for groups.
 */
class TableChoiceTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    private TicketType $sessionA;

    private TicketType $sessionB;

    private SeatingTable $t1;

    private SeatingTable $t2;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->event = Event::create([
            'user_id' => $this->organizer()->id,
            'title' => 'Social Deduction KL', 'slug' => 'social-deduction-kl',
            'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
            'starts_at' => now()->addDays(5),
            'ticketing_mode' => 'tables', 'table_choice' => true,
        ]);
        $this->sessionA = $this->event->ticketTypes()->create(['name' => 'Session A', 'kind' => 'paid', 'price' => 20, 'min_per_order' => 1, 'max_per_order' => 10]);
        $this->sessionB = $this->event->ticketTypes()->create(['name' => 'Session B', 'kind' => 'paid', 'price' => 20, 'min_per_order' => 1, 'max_per_order' => 10]);
        $this->t1 = $this->event->seatingTables()->create(['name' => 'Table 1', 'capacity' => 4, 'sort_order' => 0]);
        $this->t2 = $this->event->seatingTables()->create(['name' => 'Table 2', 'capacity' => 6, 'sort_order' => 1]);
    }

    /** Start an order through the real checkout route, as a buyer's browser would. */
    private function start(TicketType $type, int $quantity): Order
    {
        $this->post(route('checkout.start', $this->event), ['items' => [['ticket_type_id' => $type->id, 'quantity' => $quantity]]])->assertRedirect();

        return Order::latest('id')->first();
    }

    private function pay(Order $order, array $tables)
    {
        return $this->post(route('checkout.pay', $order), [
            'buyer_name' => 'Ali', 'buyer_email' => 'ali@example.com', 'buyer_phone' => '0123456789', 'consent' => true,
            'tables' => $tables,
        ]);
    }

    private function settle(Order $order): void
    {
        $this->get(route('checkout.fake', $order))->assertRedirect(route('checkout.confirmation', $order));
    }

    // ---- the organizer's settings ------------------------------------------------

    public function test_the_organizer_turns_it_on_with_a_limit_and_a_group_price(): void
    {
        $host = $this->organizer();

        $this->actingAs($host)->post(route('host.events.store'), [
            'title' => 'Board Game Night', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
            'ticketing_mode' => 'tables',
            'table_choice' => true, 'table_choice_limit' => 10, 'tables_per_ticket_type' => true,
            'tables' => [['name' => 'Table 1', 'shape' => 'round', 'capacity' => 6, 'pos_x' => 0, 'pos_y' => 0]],
            'ticketTypes' => [
                ['name' => 'Session A', 'kind' => 'paid', 'price' => '20', 'group_min' => 3, 'group_price' => '15', 'min_per_order' => 1, 'max_per_order' => 10, 'is_active' => true],
                // A "group price" that costs more is not a group price.
                ['name' => 'Session B', 'kind' => 'paid', 'price' => '20', 'group_min' => 3, 'group_price' => '25', 'min_per_order' => 1, 'max_per_order' => 10, 'is_active' => true],
            ],
            'sessions' => [], 'gallery' => [], 'sections' => [],
        ])->assertSessionHasNoErrors();

        $event = Event::where('title', 'Board Game Night')->firstOrFail();
        $this->assertTrue($event->table_choice);
        $this->assertSame(10, $event->table_choice_limit);
        $this->assertTrue($event->tables_per_ticket_type);

        $a = $event->ticketTypes()->where('name', 'Session A')->first();
        $this->assertSame(3, $a->group_min);
        $this->assertSame('15.00', $a->group_price);
        $this->assertNull($event->ticketTypes()->where('name', 'Session B')->value('group_price'));
    }

    public function test_the_settings_only_apply_to_table_events(): void
    {
        $host = $this->organizer();

        $this->actingAs($host)->post(route('host.events.store'), [
            'title' => 'Plain Night', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
            'ticketing_mode' => 'general', 'table_choice' => true, 'table_choice_limit' => 5,
            'ticketTypes' => [['name' => 'GA', 'kind' => 'free', 'price' => '0', 'min_per_order' => 1, 'max_per_order' => 10, 'is_active' => true]],
            'sessions' => [], 'gallery' => [], 'sections' => [],
        ])->assertSessionHasNoErrors();

        $event = Event::where('title', 'Plain Night')->firstOrFail();
        $this->assertFalse($event->table_choice);
        $this->assertNull($event->table_choice_limit);
    }

    // ---- group price -----------------------------------------------------------------

    public function test_buying_enough_at_once_earns_the_group_price(): void
    {
        $this->sessionA->update(['group_min' => 3, 'group_price' => 15]);

        $two = app(CheckoutService::class)->start($this->event, [['ticket_type_id' => $this->sessionA->id, 'quantity' => 2]]);
        $three = app(CheckoutService::class)->start($this->event, [['ticket_type_id' => $this->sessionA->id, 'quantity' => 3]]);

        $this->assertEquals(40, $two->total);     // 2 × RM20
        $this->assertEquals(45, $three->total);   // 3 × RM15
        $this->assertEquals(15, $three->items->first()->unit_price);

        $this->get('/en-my/e/'.$this->event->slug.'/')->assertInertia(fn (Assert $page) => $page
            ->where('event.ticket_types.0.group_min', 3)
            ->where('event.ticket_types.0.group_price', 15));
    }

    // ---- picking a table -------------------------------------------------------------

    public function test_a_buyer_picks_a_table_and_their_group_sits_there(): void
    {
        $order = $this->start($this->sessionA, 3);

        $this->get(route('checkout.show', $order))->assertInertia(fn (Assert $page) => $page
            ->where('tableChoice.lines.0.quantity', 3)
            ->where('tableChoice.lines.0.tables.0.left', 4)
            ->where('tableChoice.lines.0.tables.1.left', 6));

        $item = $order->items()->first();
        $this->pay($order, [$item->id => $this->t1->id])->assertSessionHasNoErrors();
        $this->assertSame($this->t1->id, $item->fresh()->seating_table_id);

        $this->settle($order);
        $this->assertSame([$this->t1->id], $order->tickets()->pluck('seating_table_id')->unique()->values()->all());
        $this->assertCount(3, $order->tickets);
    }

    public function test_a_table_without_room_for_the_whole_group_is_refused(): void
    {
        // Someone else's pick holds three of Table 1's four seats while they pay.
        $first = $this->start($this->sessionA, 3);
        $this->pay($first, [$first->items()->first()->id => $this->t1->id])->assertSessionHasNoErrors();

        $second = $this->start($this->sessionA, 2);
        $this->get(route('checkout.show', $second))->assertInertia(fn (Assert $page) => $page->where('tableChoice.lines.0.tables.0.left', 1));

        $this->pay($second, [$second->items()->first()->id => $this->t1->id])
            ->assertSessionHasErrors('tables.'.$second->items()->first()->id);
        $this->assertNull($second->items()->first()->seating_table_id);
    }

    public function test_no_preference_is_fine_and_auto_seat_keeps_the_group_together(): void
    {
        $this->event->update(['auto_assign_tables' => true]);
        $order = $this->start($this->sessionA, 3);

        $this->pay($order, [$order->items()->first()->id => ''])->assertSessionHasNoErrors();
        $this->settle($order);

        $tables = $order->tickets()->pluck('seating_table_id')->unique();
        $this->assertCount(1, $tables, 'a group of three sits at one table');
        // The fullest table that still fits them: Table 1 (4 seats) before Table 2 (6).
        $this->assertSame($this->t1->id, $tables->first());
    }

    public function test_only_the_first_n_orders_may_pick(): void
    {
        $this->event->update(['table_choice_limit' => 1]);

        $first = $this->start($this->sessionA, 1);
        $this->pay($first, [$first->items()->first()->id => $this->t2->id])->assertSessionHasNoErrors();
        $this->settle($first);

        $this->assertSame(0, app(TableAssignmentService::class)->choicesLeft($this->event->fresh()));

        $second = $this->start($this->sessionA, 1);
        $this->get(route('checkout.show', $second))->assertInertia(fn (Assert $page) => $page->where('tableChoice', null));
        $this->pay($second, [$second->items()->first()->id => $this->t2->id])->assertSessionHasErrors('tables');

        $this->get('/en-my/e/'.$this->event->slug.'/')->assertInertia(fn (Assert $page) => $page->where('event.table_choice.left', 0));
    }

    public function test_a_buyer_who_already_holds_a_pick_keeps_it_at_the_limit(): void
    {
        $this->event->update(['table_choice_limit' => 1]);
        $order = $this->start($this->sessionA, 1);
        $item = $order->items()->first();

        $this->pay($order, [$item->id => $this->t1->id])->assertSessionHasNoErrors();

        // Back on the checkout page (say the payment window was closed): still theirs.
        $this->get(route('checkout.show', $order))->assertInertia(fn (Assert $page) => $page->where('tableChoice.lines.0.chosen', $this->t1->id));
        $this->pay($order, [$item->id => $this->t2->id])->assertSessionHasNoErrors();
        $this->assertSame($this->t2->id, $item->fresh()->seating_table_id);
    }

    public function test_each_session_has_the_tables_to_itself(): void
    {
        $this->event->update(['tables_per_ticket_type' => true]);

        $a = $this->start($this->sessionA, 4);
        $this->pay($a, [$a->items()->first()->id => $this->t1->id])->assertSessionHasNoErrors();
        $this->settle($a);

        // Table 1 is full for Session A…
        $svc = app(TableAssignmentService::class);
        $this->assertSame(0, $svc->freeSeats($this->event->fresh(), $this->sessionA->id)[$this->t1->id]);

        // …and empty for Session B.
        $b = $this->start($this->sessionB, 4);
        $this->pay($b, [$b->items()->first()->id => $this->t1->id])->assertSessionHasNoErrors();
        $this->settle($b);
        $this->assertSame(4, $b->tickets()->where('seating_table_id', $this->t1->id)->count());
    }

    public function test_refunded_tickets_give_their_seats_back(): void
    {
        $order = $this->start($this->sessionA, 4);
        $this->pay($order, [$order->items()->first()->id => $this->t1->id]);
        $this->settle($order);

        $this->assertSame(0, $this->t1->fresh()->seatsLeft());

        app(CheckoutService::class)->refund($order->fresh());

        $this->assertSame(4, $this->t1->fresh()->seatsLeft());
        $this->assertSame(4, app(TableAssignmentService::class)->freeSeats($this->event->fresh(), $this->sessionA->id)[$this->t1->id]);
    }

    public function test_the_organizer_sees_who_picked_and_cannot_overfill_a_held_table(): void
    {
        $order = $this->start($this->sessionA, 3);
        $this->pay($order, [$order->items()->first()->id => $this->t1->id]);
        $this->settle($order);

        // A walk-in issued without a table.
        $walkIn = $this->start($this->sessionA, 1);
        $this->pay($walkIn, []);
        $this->settle($walkIn);

        // Another buyer holds Table 1's last seat while paying.
        $holder = $this->start($this->sessionA, 1);
        $this->pay($holder, [$holder->items()->first()->id => $this->t1->id])->assertSessionHasNoErrors();

        $host = $this->event->user;
        $this->actingAs($host)->get(route('host.events.seating', $this->event))->assertInertia(fn (Assert $page) => $page
            ->where('event.table_choice', true)
            ->where('event.choices_used', 2)
            ->where('tickets.0.chosen', true)
            ->where('tickets.3.chosen', false));

        $ticket = $walkIn->tickets()->first();
        $this->actingAs($host)->post(route('host.events.seating.assign', $this->event), ['ticket_id' => $ticket->id, 'seating_table_id' => $this->t1->id])
            ->assertSessionHasErrors('seating');
    }
}

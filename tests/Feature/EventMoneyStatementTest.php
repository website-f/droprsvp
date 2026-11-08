<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventCategory;
use App\Models\Order;
use App\Models\Payout;
use App\Models\TicketType;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\PayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The admin's "Money & payout" statement on an event: sales, refunds, the
 * platform fee and the organizer's share, then where their payout stands.
 */
class EventMoneyStatementTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    private TicketType $three;

    private TicketType $one;

    protected function setUp(): void
    {
        parent::setUp();

        // The higher of 5% or RM2 per order.
        config(['droprsvp.platform_fee_percent' => 5, 'droprsvp.platform_fee_flat' => 2]);

        $host = $this->organizer(['name' => 'BoardLah Entertainment']);
        $category = EventCategory::firstOrCreate(['slug' => 'community'], ['name' => 'Community', 'sort_order' => 1]);

        $this->event = Event::create([
            'user_id' => $host->id, 'category_id' => $category->id,
            'title' => 'Social Deduction KL', 'slug' => 'social-deduction-kl',
            'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
            'starts_at' => now()->addDays(3),
        ]);

        $this->three = TicketType::create(['event_id' => $this->event->id, 'name' => 'Session A', 'kind' => 'paid', 'price' => 20, 'sort_order' => 1]);
        $this->one = TicketType::create(['event_id' => $this->event->id, 'name' => 'Session B', 'kind' => 'paid', 'price' => 20, 'sort_order' => 2]);
    }

    private function buy(TicketType $type, int $quantity): Order
    {
        $order = app(CheckoutService::class)->start($this->event, [['ticket_type_id' => $type->id, 'quantity' => $quantity]]);
        $order->update(['buyer_name' => 'Azfar', 'buyer_email' => 'azfar@example.com']);
        app(CheckoutService::class)->markPaid($order, 'TEST-REF', ['method' => 'fpx']);

        return $order->fresh();
    }

    private function sell(): void
    {
        $this->buy($this->three, 3);   // RM60 → fee max(3.00, 2.00) = 3.00
        $this->buy($this->one, 1);     // RM20 → fee max(1.00, 2.00) = 2.00
        $refunded = $this->buy($this->one, 1);
        app(CheckoutService::class)->refund($refunded);
    }

    public function test_the_event_statement_works_the_money_through(): void
    {
        $this->sell();

        $s = app(PayoutService::class)->eventStatement($this->event);

        $this->assertSame(3, $s['orders']);
        $this->assertSame(1, $s['refunded_orders']);
        $this->assertSame(100.0, $s['sales']);
        $this->assertSame(20.0, $s['refunds']);
        $this->assertSame(80.0, $s['net_sales']);
        $this->assertSame(5.0, $s['fees']);
        $this->assertSame(75.0, $s['organizer_share']);
        $this->assertSame(6.3, $s['effective_rate']);
        $this->assertFalse($s['released']);

        $byType = collect($s['ticket_types'])->keyBy('name');
        $this->assertSame(['name' => 'Session A', 'tickets' => 3, 'sales' => 60.0, 'fees' => 3.0, 'net' => 57.0], $byType['Session A']);
        $this->assertSame(['name' => 'Session B', 'tickets' => 1, 'sales' => 20.0, 'fees' => 2.0, 'net' => 18.0], $byType['Session B']);
    }

    public function test_the_organizer_statement_adds_up_to_what_is_owed(): void
    {
        $this->sell();
        $this->event->update(['starts_at' => now()->subDay()]);   // it has happened
        $host = $this->event->user;

        Payout::create(['user_id' => $host->id, 'reference' => 'PO-PAID', 'amount' => 30, 'currency' => 'MYR', 'status' => 'paid', 'requested_at' => now()->subDay(), 'paid_at' => now()]);
        Payout::create(['user_id' => $host->id, 'reference' => 'PO-WAIT', 'amount' => 10, 'currency' => 'MYR', 'status' => 'pending', 'requested_at' => now()]);

        $s = app(PayoutService::class)->organizerStatement($host->fresh());

        $this->assertSame(75.0, $s['earned']);
        $this->assertSame(30.0, $s['paid_out']);
        $this->assertSame(10.0, $s['requested']);
        $this->assertSame(35.0, $s['available']);
        $this->assertSame(0.0, $s['clearing']);
        // Owed is everything not yet paid, and is the three parts together.
        $this->assertSame(45.0, $s['owed']);
        $this->assertSame($s['owed'], $s['requested'] + $s['available'] + $s['clearing']);
    }

    public function test_money_in_transfer_is_not_available_to_request_again(): void
    {
        $this->sell();
        $this->event->update(['starts_at' => now()->subDay()]);
        $host = $this->event->user;

        Payout::create(['user_id' => $host->id, 'reference' => 'PO-SEND', 'amount' => 75, 'currency' => 'MYR', 'status' => 'processing', 'requested_at' => now()]);

        $this->assertSame(0.0, app(PayoutService::class)->balanceFor($host->fresh())['available']);
    }

    public function test_the_admin_event_page_carries_the_statement(): void
    {
        $this->sell();
        Role::findOrCreate('superadmin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');

        $this->actingAs($admin)->get('/admin/analytics/'.$this->event->slug)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/analytics/event')
                ->where('money.event.organizer_share', 75)
                ->where('money.organizer.name', 'BoardLah Entertainment')
                ->where('money.organizer.owed', 75));
    }
}

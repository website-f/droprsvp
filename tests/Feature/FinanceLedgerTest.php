<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Order;
use App\Models\Payout;
use App\Models\User;
use App\Support\PaymentMethod;
use App\Support\RolePermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The finance ledger as double entry.
 *
 * One paid order is up to three movements — the gross in, our commission in,
 * anything refunded out — and collapsing them into a single row is why the
 * platform's own revenue was invisible on this page.
 */
class FinanceLedgerTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $role = 'superadmin'): User
    {
        Role::findOrCreate($role, 'web');
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function eventFor(User $host, string $title = 'Gig'): Event
    {
        return Event::create([
            'user_id' => $host->id, 'title' => $title, 'slug' => 'e-'.uniqid(),
            'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
        ]);
    }

    public function test_a_paid_order_is_both_a_ticket_sale_and_a_platform_fee(): void
    {
        $event = $this->eventFor(User::factory()->create());

        Order::create([
            'reference' => 'DRSVP-A', 'event_id' => $event->id, 'status' => 'paid',
            'subtotal' => 100, 'fees' => 3, 'total' => 100, 'currency' => 'MYR',
            'buyer_name' => 'Jo', 'paid_at' => now(),
        ]);

        $this->actingAs($this->admin())->get('/admin/finance')->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                // The buyer paid 100 and the commission came out of it, so the
                // two overlap — GMV is not 103.
                ->where('kpis.ticket_sales', 100)
                ->where('kpis.platform_fees', 3)
                ->where('kpis.platform_revenue', 3)
                // Collected on the organizer's behalf, fee deducted, none paid out.
                ->where('kpis.owed_to_organizers', 97)
                ->has('transactions.data', 2));
    }

    public function test_the_platform_fee_is_a_row_of_its_own(): void
    {
        $event = $this->eventFor(User::factory()->create());

        Order::create([
            'reference' => 'DRSVP-B', 'event_id' => $event->id, 'status' => 'paid',
            'subtotal' => 50, 'fees' => 5, 'total' => 50, 'currency' => 'MYR', 'paid_at' => now(),
        ]);

        // This is the line the user could not find anywhere on the page.
        $this->actingAs($this->admin())->get('/admin/finance?type=fee')
            ->assertInertia(fn (Assert $p) => $p
                ->has('transactions.data', 1)
                ->where('transactions.data.0.type', 'fee')
                ->where('transactions.data.0.amount', 5)
                ->where('transactions.data.0.direction', 'in'));
    }

    public function test_a_partial_refund_is_counted_at_what_was_actually_refunded(): void
    {
        $event = $this->eventFor(User::factory()->create());

        // Partial refunds leave the order 'paid', so keying refunds off status
        // missed them entirely — and full ones were counted at the order total
        // rather than the refunded sum.
        Order::create([
            'reference' => 'DRSVP-C', 'event_id' => $event->id, 'status' => 'paid',
            'subtotal' => 200, 'fees' => 6, 'total' => 200, 'refunded_amount' => 45,
            'currency' => 'MYR', 'paid_at' => now(), 'refunded_at' => now(),
        ]);

        $this->actingAs($this->admin())->get('/admin/finance')
            ->assertInertia(fn (Assert $p) => $p
                ->where('kpis.refunds', 45)
                ->where('kpis.ticket_sales', 200));
    }

    public function test_an_order_with_no_refund_produces_no_refund_row(): void
    {
        $event = $this->eventFor(User::factory()->create());

        Order::create([
            'reference' => 'DRSVP-D', 'event_id' => $event->id, 'status' => 'paid',
            'total' => 20, 'fees' => 3, 'currency' => 'MYR', 'paid_at' => now(),
        ]);

        $this->actingAs($this->admin())->get('/admin/finance?type=refund')
            ->assertInertia(fn (Assert $p) => $p->has('transactions.data', 0));
    }

    public function test_the_ledger_can_be_filtered_to_one_event(): void
    {
        $host = User::factory()->create();
        $wanted = $this->eventFor($host, 'Wanted');
        $other = $this->eventFor($host, 'Other');

        foreach ([[$wanted, 'DRSVP-E1'], [$other, 'DRSVP-E2']] as [$event, $ref]) {
            Order::create([
                'reference' => $ref, 'event_id' => $event->id, 'status' => 'paid',
                'total' => 10, 'fees' => 3, 'currency' => 'MYR', 'paid_at' => now(),
            ]);
        }

        $this->actingAs($this->admin())->get("/admin/finance?event={$wanted->id}")
            ->assertInertia(fn (Assert $p) => $p
                // Ticket + fee for the wanted event, nothing from the other.
                ->has('transactions.data', 2)
                // The tiles narrow with it, or they would contradict the rows.
                ->where('kpis.ticket_sales', 10));
    }

    public function test_the_ledger_can_be_filtered_to_one_organizer(): void
    {
        $mine = User::factory()->create(['name' => 'Mine']);
        $theirs = User::factory()->create(['name' => 'Theirs']);

        Order::create([
            'reference' => 'DRSVP-F1', 'event_id' => $this->eventFor($mine)->id, 'status' => 'paid',
            'total' => 70, 'fees' => 3, 'currency' => 'MYR', 'paid_at' => now(),
        ]);
        Order::create([
            'reference' => 'DRSVP-F2', 'event_id' => $this->eventFor($theirs)->id, 'status' => 'paid',
            'total' => 30, 'fees' => 3, 'currency' => 'MYR', 'paid_at' => now(),
        ]);

        $this->actingAs($this->admin())->get("/admin/finance?organizer={$mine->id}")
            ->assertInertia(fn (Assert $p) => $p->where('kpis.ticket_sales', 70));
    }

    public function test_incoming_and_outgoing_can_be_separated(): void
    {
        $host = User::factory()->create();

        Order::create([
            'reference' => 'DRSVP-G', 'event_id' => $this->eventFor($host)->id, 'status' => 'paid',
            'total' => 90, 'fees' => 3, 'currency' => 'MYR', 'paid_at' => now(),
        ]);
        Payout::create([
            'user_id' => $host->id, 'reference' => 'PO-G', 'amount' => 60,
            'currency' => 'MYR', 'status' => 'paid', 'paid_at' => now(),
        ]);

        $this->actingAs($this->admin())->get('/admin/finance?direction=out')
            ->assertInertia(fn (Assert $p) => $p
                ->has('transactions.data', 1)
                ->where('transactions.data.0.type', 'payout'));

        $this->actingAs($this->admin())->get('/admin/finance?direction=in')
            ->assertInertia(fn (Assert $p) => $p->has('transactions.data', 2));
    }

    public function test_the_payment_method_is_superadmin_only(): void
    {
        $host = User::factory()->create();

        Order::create([
            'reference' => 'DRSVP-H', 'event_id' => $this->eventFor($host)->id, 'status' => 'paid',
            'total' => 40, 'fees' => 3, 'currency' => 'MYR', 'paid_at' => now(),
            'payment_method' => 'fpx', 'payment_brand' => 'maybank2u',
        ]);

        $this->actingAs($this->admin('superadmin'))->get('/admin/finance')
            ->assertInertia(fn (Assert $p) => $p
                ->where('showMethod', true)
                ->where('transactions.data.0.payment.brand_label', 'Maybank')
                ->where('transactions.data.0.payment.method', 'fpx'));

        // Staff can reconcile amounts without seeing the buyer's instrument.
        // They need the section granted first, or the middleware 403s before
        // the page is ever rendered and this would assert nothing.
        RolePermissions::save(['staff' => ['finance']]);

        $this->actingAs($this->admin('staff'))->get('/admin/finance')
            ->assertInertia(fn (Assert $p) => $p
                ->where('showMethod', false)
                ->where('transactions.data.0.payment', null));
    }

    public function test_a_payout_is_labelled_with_the_organizers_bank(): void
    {
        $host = User::factory()->create();
        // What CHIP Send stores: a SWIFT/BIC code, not one of our brand keys.
        $host->forceFill(['payout_bank_code' => 'MBBEMYKL'])->save();

        Payout::create([
            'user_id' => $host->id, 'reference' => 'PO-I', 'amount' => 25,
            'currency' => 'MYR', 'status' => 'paid', 'paid_at' => now(),
        ]);

        $this->actingAs($this->admin())->get('/admin/finance')
            ->assertInertia(fn (Assert $p) => $p
                ->where('transactions.data.0.payment.brand_label', 'Maybank')
                ->where('transactions.data.0.payment.method', 'bank_transfer'));
    }

    public function test_chip_payloads_are_read_into_a_method_and_a_brand(): void
    {
        $this->assertSame(
            ['method' => 'fpx', 'brand' => 'maybank2u'],
            PaymentMethod::fromChip(['transaction_data' => ['payment_method' => 'fpx_b2c', 'extra' => ['bank' => 'MAYBANK2U']]]),
        );

        $this->assertSame(
            ['method' => 'card', 'brand' => 'visa'],
            PaymentMethod::fromChip(['transaction_data' => ['payment_method' => 'card', 'extra' => ['brand' => 'Visa']]]),
        );

        // A rail we recognise but an instrument we don't: keep the rail rather
        // than guessing, so the row still says something true.
        $this->assertSame(
            ['method' => 'fpx', 'brand' => null],
            PaymentMethod::fromChip(['transaction_data' => ['payment_method' => 'fpx_b2b1', 'extra' => ['bank' => 'SOME NEW BANK']]]),
        );

        // Nothing usable at all must not throw — settlement matters more than
        // knowing how it was paid.
        $this->assertSame(['method' => null, 'brand' => null], PaymentMethod::fromChip(null));
        $this->assertSame(['method' => null, 'brand' => null], PaymentMethod::fromChip(['transaction_data' => 'not-an-array']));
    }

    public function test_an_unknown_instrument_never_produces_a_broken_badge(): void
    {
        // describe() must return either null or a complete shape; a half-filled
        // one would render an empty chip on the finance page.
        $this->assertNull(PaymentMethod::describe(null, null));

        $described = PaymentMethod::describe(null, 'some_new_wallet');
        $this->assertNotNull($described);
        $this->assertSame('Some New Wallet', $described['brand_label']);
        $this->assertArrayHasKey('label', $described);
        $this->assertArrayHasKey('color', $described);
    }

    public function test_the_filter_dropdowns_only_offer_things_that_have_money(): void
    {
        $host = User::factory()->create(['name' => 'Earning Host']);
        $paid = $this->eventFor($host, 'Sold Out');
        $this->eventFor($host, 'Never Sold A Thing');

        Order::create([
            'reference' => 'DRSVP-J', 'event_id' => $paid->id, 'status' => 'paid',
            'total' => 10, 'fees' => 3, 'currency' => 'MYR', 'paid_at' => now(),
        ]);

        $this->actingAs($this->admin())->get('/admin/finance')
            ->assertInertia(fn (Assert $p) => $p
                ->has('options.events', 1)
                ->where('options.events.0.label', 'Sold Out'));
    }
}

<?php

namespace Tests\Feature;

use App\Mail\PayoutPaidMail;
use App\Models\DiscountCode;
use App\Models\EmailConsent;
use App\Models\Event;
use App\Models\Order;
use App\Models\Payout;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Payouts an admin records (with proof), the organizer's customer list,
 * promo windows in event time, and unlisted events.
 */
class PayoutsCustomersAndPromosTest extends TestCase
{
    use RefreshDatabase;

    private function superadmin(): User
    {
        Role::findOrCreate('superadmin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');

        return $admin;
    }

    private function event(User $host, array $attrs = []): Event
    {
        return Event::create(array_merge([
            'user_id' => $host->id, 'title' => 'Board Game Night', 'slug' => 'board-game-night-'.Str::lower(Str::random(4)),
            'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
            'starts_at' => now()->subDays(3),
        ], $attrs));
    }

    private function sale(Event $event, float $total, string $email = 'aina@example.test', string $name = 'Aina', int $tickets = 1): Order
    {
        $order = Order::create([
            'reference' => 'DRSVP-'.Str::upper(Str::random(8)), 'event_id' => $event->id, 'status' => 'paid',
            'subtotal' => $total, 'total' => $total, 'fees' => 0, 'paid_at' => now(),
            'buyer_name' => $name, 'buyer_email' => $email, 'buyer_phone' => '0123456789',
        ]);
        $order->items()->create(['name' => 'Entry', 'unit_price' => $total / $tickets, 'quantity' => $tickets, 'line_total' => $total]);

        return $order;
    }

    // ---- payouts ---------------------------------------------------------------------

    public function test_an_admin_records_a_payout_already_paid_with_proof(): void
    {
        Mail::fake();
        $host = $this->organizer(['name' => 'BoardLah']);
        $this->sale($this->event($host), 100);

        $this->actingAs($this->superadmin())->post('/admin/payouts', [
            'user_id' => $host->id, 'amount' => 60, 'paid' => true,
            'method' => 'DuitNow', 'note' => 'Maybank ref 88231', 'proof' => '/storage/cms/slip.png',
        ])->assertSessionHasNoErrors();

        $payout = Payout::where('user_id', $host->id)->firstOrFail();
        $this->assertSame('paid', $payout->status);
        $this->assertSame('DuitNow', $payout->method);
        $this->assertSame('/storage/cms/slip.png', $payout->proof);
        $this->assertNotNull($payout->created_by);
        Mail::assertSent(PayoutPaidMail::class);

        // The organizer sees it, slip and all.
        $this->actingAs($host)->get('/host/payouts')->assertInertia(fn (Assert $page) => $page
            ->where('payouts.0.proof', '/storage/cms/slip.png')
            ->where('payouts.0.by_admin', true));
    }

    public function test_a_payout_cannot_be_more_than_is_owed(): void
    {
        $host = $this->organizer();
        $this->sale($this->event($host), 100);
        Payout::create(['user_id' => $host->id, 'reference' => 'PO-OLD', 'amount' => 70, 'currency' => 'MYR', 'status' => 'paid', 'requested_at' => now(), 'paid_at' => now()]);

        $this->actingAs($this->superadmin())->post('/admin/payouts', ['user_id' => $host->id, 'amount' => 40, 'paid' => false])
            ->assertSessionHasErrors('amount');
        $this->assertSame(1, Payout::count());
    }

    public function test_a_pending_request_is_marked_paid_with_a_slip(): void
    {
        Mail::fake();
        $host = $this->organizer();
        $payout = Payout::create(['user_id' => $host->id, 'reference' => 'PO-REQ', 'amount' => 50, 'currency' => 'MYR', 'status' => 'pending', 'requested_at' => now()]);

        $this->actingAs($this->superadmin())->post('/admin/payouts/PO-REQ/paid', ['method' => 'Bank transfer', 'note' => 'CIMB 5521', 'proof' => '/storage/cms/x.jpg'])
            ->assertSessionHasNoErrors();

        $payout->refresh();
        $this->assertSame(['paid', 'Bank transfer', 'CIMB 5521', '/storage/cms/x.jpg'], [$payout->status, $payout->method, $payout->note, $payout->proof]);
    }

    public function test_the_organizer_search_shows_what_is_owed(): void
    {
        $host = $this->organizer(['name' => 'Nurul']);
        $host->organizerProfile()->create(['business_name' => 'BoardLah Entertainment', 'status' => 'approved']);
        $this->sale($this->event($host), 80);

        $this->actingAs($this->superadmin())->getJson('/admin/payouts/organizers?q=boardlah')
            ->assertOk()
            ->assertJsonPath('0.name', 'BoardLah Entertainment')
            ->assertJsonPath('0.outstanding', 80);
    }

    public function test_only_a_superadmin_records_payouts(): void
    {
        $host = $this->organizer();
        $this->actingAs($host)->post('/admin/payouts', ['user_id' => $host->id, 'amount' => 1, 'paid' => true])->assertForbidden();
    }

    // ---- customers -----------------------------------------------------------------------

    public function test_customers_are_one_row_per_person_across_events_and_export(): void
    {
        $host = $this->organizer();
        $a = $this->event($host, ['title' => 'Night A']);
        $b = $this->event($host, ['title' => 'Night B']);
        $this->sale($a, 40, 'Aina@Example.test', 'Aina', 2);
        $this->sale($b, 20, 'aina@example.test', 'Aina', 1);
        $this->sale($a, 15, 'badrul@example.test', 'Badrul', 1);
        // Someone else's event is not their customer.
        $this->sale($this->event($this->organizer()), 99, 'stranger@example.test', 'Stranger');
        EmailConsent::create(['email' => 'aina@example.test', 'scope' => 'organizer:'.$host->id, 'organizer_id' => $host->id, 'status' => 'subscribed', 'source' => 'checkout', 'consented_at' => now()]);

        $this->actingAs($host)->get('/host/customers?sort=spent')->assertInertia(fn (Assert $page) => $page
            ->component('host/customers')
            ->where('totals.people', 2)
            ->where('totals.tickets', 4)
            ->where('customers.data.0.email', 'aina@example.test')
            ->where('customers.data.0.orders', 2)
            ->where('customers.data.0.tickets', 3)
            ->where('customers.data.0.spent', 60)
            ->where('customers.data.0.subscribed', true)
            ->where('customers.data.1.subscribed', false));

        $this->actingAs($host)->get('/host/customers?q=badrul')->assertInertia(fn (Assert $page) => $page->where('customers.total', 1));
        $this->actingAs($host)->get('/host/customers?event='.$b->slug)->assertInertia(fn (Assert $page) => $page->where('customers.total', 1));

        $csv = $this->actingAs($host)->get('/host/customers/export')->streamedContent();
        $this->assertStringContainsString('aina@example.test', $csv);
        $this->assertStringNotContainsString('stranger@example.test', $csv);
    }

    // ---- promo windows -----------------------------------------------------------------

    public function test_a_promo_ending_on_a_day_lasts_until_1159pm_that_day_in_event_time(): void
    {
        $host = $this->organizer();
        $event = $this->event($host, ['starts_at' => now()->addMonth()]);

        $this->actingAs($host)->post("/host/events/{$event->slug}/discounts", [
            'code' => 'EARLY', 'kind' => 'percent', 'value' => 10,
            'starts_at' => '2026-11-10', 'ends_at' => '2026-11-14', 'is_active' => true,
        ])->assertSessionHasNoErrors();

        $code = DiscountCode::where('code', 'EARLY')->first();
        $this->assertSame('2026-11-09 16:00:00', $code->starts_at->utc()->format('Y-m-d H:i:s')); // 12:00 AM, 10 Nov KL
        $this->assertSame('2026-11-14 15:59:59', $code->ends_at->utc()->format('Y-m-d H:i:s')); // 11:59:59 PM, 14 Nov KL

        Carbon::setTestNow(Carbon::parse('2026-11-14 23:30', 'Asia/Kuala_Lumpur'));
        $this->assertNull($code->fresh()->rejectionReason(100));
        Carbon::setTestNow(Carbon::parse('2026-11-15 00:00:30', 'Asia/Kuala_Lumpur'));
        $this->assertNotNull($code->fresh()->rejectionReason(100));
        Carbon::setTestNow();
    }

    // ---- unlisted ----------------------------------------------------------------------

    public function test_an_unlisted_event_opens_and_sells_by_link_but_is_not_indexed_or_listed(): void
    {
        $event = $this->event($this->organizer(), ['visibility' => 'unlisted', 'starts_at' => now()->addWeek(), 'title' => 'Secret Night']);
        $type = $event->ticketTypes()->create(['name' => 'Entry', 'kind' => 'free', 'price' => 0, 'min_per_order' => 1, 'max_per_order' => 5]);

        $html = $this->get('/en-my/e/'.$event->slug.'/')->assertOk()->getContent();
        $this->assertStringContainsString('noindex', $html);

        $this->post(route('checkout.start', $event), ['items' => [['ticket_type_id' => $type->id, 'quantity' => 1]]])->assertRedirect();
        $this->assertSame(1, Order::where('event_id', $event->id)->count());

        $this->assertFalse(Event::published()->whereKey($event->id)->exists());
    }

    public function test_a_private_event_does_not_sell_to_strangers(): void
    {
        $event = $this->event($this->organizer(), ['visibility' => 'private', 'starts_at' => now()->addWeek()]);
        $type = $event->ticketTypes()->create(['name' => 'Entry', 'kind' => 'free', 'price' => 0, 'min_per_order' => 1, 'max_per_order' => 5]);

        $this->post(route('checkout.start', $event), ['items' => [['ticket_type_id' => $type->id, 'quantity' => 1]]])->assertNotFound();
    }
}

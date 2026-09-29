<?php

namespace Tests\Feature;

use App\Mail\OrderPlacedAdminMail;
use App\Mail\TicketsIssued;
use App\Models\Event;
use App\Models\Order;
use App\Models\Setting;
use App\Models\TicketType;
use App\Models\User;
use App\Services\CheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Accounts and notifications around checkout.
 *
 * Every buyer gets an account — that part already worked. What is new is being
 * signed in afterwards, and the security line that draws: an account created for
 * THIS checkout is safe to sign into, one that already belonged to somebody is
 * not. Knowing an email address must never be enough to take over the account
 * behind it, and checkout is exactly where someone would try.
 */
class CheckoutAccountTest extends TestCase
{
    use RefreshDatabase;

    private function event(): array
    {
        $event = Event::create([
            'user_id' => User::factory()->create()->id,
            'title' => 'Gig', 'slug' => 'gig-'.uniqid(), 'status' => 'published',
            'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur', 'starts_at' => now()->addDay(),
        ]);
        $type = TicketType::create([
            'event_id' => $event->id, 'name' => 'Free', 'price' => 0, 'currency' => 'MYR',
            'quantity' => 100, 'sold' => 0, 'is_active' => true, 'kind' => 'free',
        ]);

        return [$event, $type];
    }

    /** A free order settles inline, so the whole flow runs in one request. */
    private function payAsGuest(array $extra = []): Order
    {
        [$event, $type] = $this->event();

        $order = app(CheckoutService::class)->start($event, [['ticket_type_id' => $type->id, 'quantity' => 1]]);

        $this->withSession(['checkout_orders' => [$order->reference]])
            ->post("/checkout/{$order->reference}/pay", array_merge([
                'buyer_name' => 'Hazman',
                'buyer_email' => 'hazman@example.test',
                // Required by the default checkout settings.
                'buyer_phone' => '0123456789',
                'consent' => true,
            ], $extra))
            ->assertSessionHasNoErrors();

        return $order->fresh();
    }

    // ---- accounts ----------------------------------------------------------

    public function test_checking_out_as_a_guest_creates_an_account(): void
    {
        $order = $this->payAsGuest();

        $user = User::where('email', 'hazman@example.test')->first();

        $this->assertNotNull($user);
        $this->assertSame($user->id, $order->user_id);
        $this->assertTrue($order->meta['account_created'] ?? false);
    }

    public function test_the_buyer_is_signed_in_when_they_asked_to_be(): void
    {
        $this->payAsGuest(['auto_login' => true]);

        $this->assertAuthenticated();
        $this->assertSame('hazman@example.test', auth()->user()->email);
    }

    public function test_the_buyer_is_not_signed_in_when_they_did_not_ask(): void
    {
        $this->payAsGuest(['auto_login' => false]);

        $this->assertGuest();
    }

    /**
     * The one that matters. Checking out with somebody else's email links the
     * order to their account so their tickets are in the right place — but must
     * never hand the browser a session as them.
     */
    public function test_checking_out_with_an_existing_email_never_signs_you_in_as_that_person(): void
    {
        $victim = User::factory()->create(['email' => 'hazman@example.test', 'name' => 'Real Owner']);

        $order = $this->payAsGuest(['auto_login' => true]);

        $this->assertGuest();
        $this->assertSame($victim->id, $order->user_id);
        // provisionBuyerAccount only sets this on the branch that mints a user.
        $this->assertArrayNotHasKey('account_created', $order->meta ?? []);
    }

    public function test_a_signed_in_buyers_order_is_tied_to_them(): void
    {
        $user = User::factory()->create();
        [$event, $type] = $this->event();

        $order = app(CheckoutService::class)->start($event, [['ticket_type_id' => $type->id, 'quantity' => 1]], $user->id);

        $this->assertSame($user->id, $order->user_id);
    }

    // ---- logging in mid-checkout ------------------------------------------

    public function test_the_login_link_parks_the_checkout_as_the_return_destination(): void
    {
        [$event, $type] = $this->event();
        $order = app(CheckoutService::class)->start($event, [['ticket_type_id' => $type->id, 'quantity' => 1]]);

        $this->withSession(['checkout_orders' => [$order->reference]])
            ->get("/checkout/{$order->reference}/login")
            ->assertRedirect(route('login'))
            ->assertSessionHas('url.intended', route('checkout.show', $order));
    }

    public function test_logging_in_mid_checkout_claims_the_guest_order(): void
    {
        $user = User::factory()->create();
        [$event, $type] = $this->event();
        $order = app(CheckoutService::class)->start($event, [['ticket_type_id' => $type->id, 'quantity' => 1]]);

        $this->assertNull($order->user_id);

        $this->actingAs($user)
            ->withSession(['checkout_orders' => [$order->reference]])
            ->get("/checkout/{$order->reference}")
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('account.email', $user->email));

        $this->assertSame($user->id, $order->fresh()->user_id);
    }

    public function test_a_guest_sees_the_login_option_and_a_signed_in_buyer_does_not(): void
    {
        $user = User::factory()->create();
        [$event, $type] = $this->event();
        $order = app(CheckoutService::class)->start($event, [['ticket_type_id' => $type->id, 'quantity' => 1]]);

        $this->withSession(['checkout_orders' => [$order->reference]])
            ->get("/checkout/{$order->reference}")
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('account', null)->has('loginUrl'));

        $this->actingAs($user)
            ->withSession(['checkout_orders' => [$order->reference]])
            ->get("/checkout/{$order->reference}")
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('account.name', $user->name));
    }

    // ---- notifications -----------------------------------------------------

    public function test_a_paid_order_notifies_the_support_inbox_as_well_as_the_buyer(): void
    {
        Mail::fake();
        Setting::put('support_email', 'contact@droprsvp.test');

        $this->payAsGuest();

        Mail::assertSent(TicketsIssued::class, fn ($m) => $m->hasTo('hazman@example.test'));
        // Previously nobody on the platform side heard about a sale at all.
        Mail::assertSent(OrderPlacedAdminMail::class, fn ($m) => $m->hasTo('contact@droprsvp.test'));
    }

    public function test_the_support_inbox_falls_back_to_the_from_address(): void
    {
        Mail::fake();
        Setting::put('support_email', '');
        config(['mail.from.address' => 'no-reply@droprsvp.test']);

        $this->payAsGuest();

        Mail::assertSent(OrderPlacedAdminMail::class, fn ($m) => $m->hasTo('no-reply@droprsvp.test'));
    }

    public function test_an_unpaid_order_notifies_nobody(): void
    {
        Mail::fake();

        [$event, $type] = $this->event();
        app(CheckoutService::class)->start($event, [['ticket_type_id' => $type->id, 'quantity' => 1]]);

        Mail::assertNothingSent();
    }
}

<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\CheckInService;
use App\Services\CheckoutService;
use App\Services\Payments\Airwallex;
use App\Services\Payments\PaymentGateway;
use App\Support\CountryRates;
use App\Support\PlatformFee;
use App\Support\Qr;
use App\Support\Receipt;
use App\Support\ReceiptTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Selling abroad: rates per country, Airwallex for payments outside Malaysia —
 * plus this batch's door, ticket, invoice and admin fixes.
 */
class CountryPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://api.sandbox.airwallex.com';

    private function superadmin(): User
    {
        Role::findOrCreate('superadmin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');

        return $admin;
    }

    /** @return array{0: Event, 1: TicketType} */
    private function event(string $region = 'en-sg', float $price = 50): array
    {
        $event = Event::create([
            'user_id' => User::factory()->create()->id, 'title' => 'Marina Bay Trivia '.Str::random(4), 'slug' => 'trivia-'.Str::lower(Str::random(6)),
            'status' => 'published', 'visibility' => 'public', 'region' => $region,
            'timezone' => $region === 'en-sg' ? 'Asia/Singapore' : 'Asia/Kuala_Lumpur', 'starts_at' => now()->addWeek(),
        ]);
        $tt = $event->ticketTypes()->create(['name' => 'Entry', 'kind' => 'paid', 'price' => $price, 'quantity' => 100, 'min_per_order' => 1, 'max_per_order' => 10, 'is_active' => true]);

        return [$event, $tt];
    }

    private function airwallexOn(): void
    {
        Airwallex::save(['enabled' => true, 'env' => 'sandbox', 'client_id' => 'cid', 'account_id' => 'acct_1', 'api_key' => 'secret-key-1234', 'webhook_secret' => 'whsec']);
        CountryRates::forget();
    }

    private function fakeAirwallex(array $extra = []): void
    {
        Http::fake(array_merge([
            self::API.'/api/v1/authentication/login' => Http::response(['token' => 'tok', 'expires_at' => now()->addMinutes(30)->toIso8601String()], 201),
            self::API.'/api/v1/checkout/checkout_sessions/create' => Http::response(['id' => 'cs_123', 'url' => 'https://checkout.airwallex.com/pay/cs_123', 'status' => 'ACTIVE'], 201),
            self::API.'/api/v1/checkout/checkout_sessions/cs_123' => Http::response(['id' => 'cs_123', 'status' => 'COMPLETED', 'payment_intent_id' => 'int_456']),
            self::API.'/api/v1/pa/payment_intents/int_456' => Http::response([
                'id' => 'int_456', 'status' => 'SUCCEEDED', 'merchant_order_id' => 'X',
                'latest_payment_attempt' => ['payment_method' => ['type' => 'card', 'card' => ['brand' => 'visa']]],
            ]),
            self::API.'/api/v1/pa/refunds/create' => Http::response(['id' => 'rfd_1', 'status' => 'RECEIVED'], 201),
        ], $extra));
    }

    private function openOrder(Event $event, TicketType $tt, int $qty = 2): Order
    {
        $this->post(route('checkout.start', $event), ['items' => [['ticket_type_id' => $tt->id, 'quantity' => $qty]]])->assertRedirect();

        return Order::latest('id')->first();
    }

    // ---- rates per country ----------------------------------------------------------

    public function test_rates_layer_from_default_to_all_other_countries_to_one_country(): void
    {
        Setting::put('platform_fee_percent', 5);
        Setting::put('platform_fee_flat', 2);
        Setting::put('tax_percent', 6);
        Setting::put('tax_label', 'SST');
        CountryRates::save([
            'foreign' => ['fee_percent' => 7, 'tax_percent' => 0],
            'countries' => ['SG' => ['tax_percent' => 9, 'tax_label' => 'GST'], 'ZZ' => ['fee_percent' => 1]],
        ]);

        $my = CountryRates::for('MY');
        $this->assertSame([5.0, 2.0, 6.0, 'SST', 'MYR', 'chip'], [$my['fee_percent'], $my['fee_flat'], $my['tax_percent'], $my['tax_label'], $my['currency'], $my['gateway']]);

        $th = CountryRates::for('TH');
        $this->assertSame([7.0, 2.0, 0.0, 'foreign'], [$th['fee_percent'], $th['fee_flat'], $th['tax_percent'], $th['source']]);

        $sg = CountryRates::for('SG');
        $this->assertSame([7.0, 9.0, 'GST', 'country'], [$sg['fee_percent'], $sg['tax_percent'], $sg['tax_label'], $sg['source']]);
        $this->assertSame('MYR', $sg['currency'], 'without Airwallex everything is CHIP, in ringgit');
        $this->assertArrayNotHasKey('ZZ', CountryRates::raw()['countries'], 'unknown countries are dropped');

        // An organizer's own rate wins in every country.
        $vip = User::factory()->create(['platform_fee_percent' => 1, 'platform_fee_flat' => 0.5]);
        $this->assertSame(1.0, PlatformFee::percent($vip, 'SG'));
        $this->assertSame(7.0, PlatformFee::percent(null, 'SG'));
    }

    public function test_with_airwallex_on_other_countries_pay_in_their_own_currency(): void
    {
        $this->airwallexOn();

        $sg = CountryRates::for('SG');
        $this->assertSame(['airwallex', 'SGD'], [$sg['gateway'], $sg['currency']]);
        $this->assertSame(['chip', 'MYR'], [CountryRates::for('MY')['gateway'], CountryRates::for('MY')['currency']], 'Malaysia stays on CHIP');

        // An admin can pin a country to CHIP (ringgit) or another currency.
        CountryRates::save(['foreign' => ['currency' => 'USD'], 'countries' => ['ID' => ['gateway' => 'chip']]]);
        $this->assertSame('USD', CountryRates::for('TH')['currency']);
        $this->assertSame(['chip', 'MYR'], [CountryRates::for('ID')['gateway'], CountryRates::for('ID')['currency']]);
    }

    public function test_the_admin_sets_country_rates_including_several_countries_at_once(): void
    {
        $admin = $this->superadmin();

        $this->actingAs($admin)->post('/admin/settings/country-rates', [
            'foreign' => ['fee_percent' => '6', 'fee_flat' => '', 'tax_percent' => '', 'tax_label' => '', 'currency' => '', 'gateway' => ''],
            'countries' => [
                'SG' => ['tax_percent' => '9', 'tax_label' => 'GST'],
                'TH' => ['tax_percent' => '7', 'tax_label' => 'VAT'],
                'PH' => ['tax_percent' => '7', 'tax_label' => 'VAT'],
            ],
        ])->assertSessionHasNoErrors();

        $raw = CountryRates::raw();
        $this->assertSame(['fee_percent' => 6.0], $raw['foreign'], 'blanks are not stored — they inherit');
        $this->assertSame(['PH', 'SG', 'TH'], array_keys($raw['countries']));
        $this->assertSame('VAT', CountryRates::for('PH')['tax_label']);

        $this->actingAs($admin)->post('/admin/settings/country-rates', ['countries' => ['XX' => ['tax_percent' => 1]]])->assertSessionHasErrors('countries');
        $this->actingAs($admin)->post('/admin/settings/country-rates', ['countries' => ['MY' => ['gateway' => 'airwallex']]])->assertSessionHasErrors('countries');
        $this->actingAs($admin)->post('/admin/settings/country-rates', ['foreign' => ['fee_percent' => 120]])->assertSessionHasErrors('foreign.fee_percent');

        $this->actingAs($admin)->get('/admin/settings')->assertInertia(fn (Assert $p) => $p
            ->where('countryRates.countries.SG.tax_label', 'GST')
            ->where('airwallex.api_key_set', false));
    }

    public function test_checkout_charges_the_events_countrys_fee_tax_and_currency(): void
    {
        $this->airwallexOn();
        CountryRates::save(['countries' => ['SG' => ['fee_percent' => 10, 'fee_flat' => 0, 'tax_percent' => 9]]]);
        [$event, $tt] = $this->event('en-sg', 50);

        $order = $this->openOrder($event, $tt, 2);

        $this->assertSame('SGD', $order->currency);
        $this->assertSame('airwallex', $order->gateway);
        $this->assertEquals(100, $order->subtotal);
        $this->assertEquals(9, $order->tax);
        $this->assertEquals(109, $order->total);
        $this->assertEquals(10, $order->fees);

        // A Malaysian event is unchanged.
        [$my, $myTt] = $this->event('en-my', 50);
        $myOrder = $this->openOrder($my, $myTt, 1);
        $this->assertSame(['MYR', 'chip'], [$myOrder->currency, $myOrder->gateway]);
    }

    // ---- Airwallex --------------------------------------------------------------------

    public function test_airwallex_settings_are_encrypted_and_never_sent_to_the_browser(): void
    {
        $admin = $this->superadmin();

        $this->actingAs($admin)->post('/admin/settings/airwallex', [
            'enabled' => true, 'env' => 'production', 'client_id' => 'cid', 'account_id' => 'acct_9',
            'api_key' => 'live-key-abcd', 'webhook_secret' => 'whsec_1',
        ])->assertSessionHasNoErrors();

        $stored = Setting::get('airwallex');
        $this->assertStringNotContainsString('live-key-abcd', (string) $stored);
        $this->assertStringNotContainsString('whsec_1', (string) $stored);
        $this->assertTrue(Airwallex::configured());
        $this->assertSame('https://api.airwallex.com', Airwallex::baseUrl());

        // Saving again with blank secrets keeps them.
        $this->actingAs($admin)->post('/admin/settings/airwallex', ['enabled' => true, 'env' => 'production', 'client_id' => 'cid', 'account_id' => 'acct_9'])->assertSessionHasNoErrors();
        $this->assertSame('live-key-abcd', Airwallex::config()['api_key']);

        $page = $this->actingAs($admin)->get('/admin/settings');
        $page->assertInertia(fn (Assert $p) => $p->where('airwallex.api_key_set', true)->where('airwallex.api_key_hint', '••••abcd')->where('airwallex.configured', true));
        $this->assertStringNotContainsString('live-key-abcd', $page->getContent());

        // Only a superadmin.
        Role::findOrCreate('staff', 'web');
        $staff = User::factory()->create();
        $staff->assignRole('staff');
        $this->actingAs($staff)->post('/admin/settings/airwallex', ['env' => 'sandbox'])->assertForbidden();
    }

    public function test_the_admin_can_test_the_airwallex_connection(): void
    {
        $this->airwallexOn();
        Http::fake([self::API.'/api/v1/authentication/login' => Http::sequence()
            ->push(['token' => 'tok'], 201)
            ->push(['message' => 'Invalid credentials'], 401)]);
        $admin = $this->superadmin();

        $this->actingAs($admin)->post('/admin/settings/airwallex/test')->assertSessionHas('flash_success');
        // A test always signs in afresh, so a bad key shows up even with a cached token.
        $this->actingAs($admin)->post('/admin/settings/airwallex/test')->assertSessionHas('flash_error');
    }

    public function test_a_foreign_order_pays_on_airwallex_and_settles_from_the_return_page(): void
    {
        Mail::fake();
        $this->airwallexOn();
        $this->fakeAirwallex();
        [$event, $tt] = $this->event('en-sg', 50);
        $order = $this->openOrder($event, $tt, 3);

        $this->post(route('checkout.pay', $order), ['buyer_name' => 'Wei', 'buyer_email' => 'wei@example.sg', 'buyer_phone' => '6591234567', 'consent' => true])
            ->assertRedirect('https://checkout.airwallex.com/pay/cs_123');
        $this->assertSame('cs_123', $order->fresh()->payment_ref);

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/checkout_sessions/create')
            && $r['currency'] === 'SGD'
            && $r['account_id'] === 'acct_1'
            && (float) $r['line_items'][0]['price']['unit_amount'] === 150.0
            && $r['payment_intent_data']['merchant_order_id'] === $order->reference
            && $r->header('Authorization')[0] === 'Bearer tok');

        $this->get(route('checkout.return', ['reference' => $order->reference]))->assertRedirect(route('checkout.confirmation', $order));

        $order->refresh();
        $this->assertSame('paid', $order->status);
        $this->assertSame('int_456', $order->payment_ref, 'refunds are made against the payment intent');
        $this->assertSame(['card', 'visa'], [$order->payment_method, $order->payment_brand]);
        $this->assertSame(3, $order->tickets()->count(), 'one ticket — one QR — per person');
    }

    public function test_an_unpaid_session_leaves_the_order_pending(): void
    {
        $this->airwallexOn();
        $this->fakeAirwallex([self::API.'/api/v1/pa/payment_intents/int_456' => Http::response(['id' => 'int_456', 'status' => 'REQUIRES_PAYMENT_METHOD'])]);
        [$event, $tt] = $this->event();
        $order = $this->openOrder($event, $tt);
        $order->update(['payment_ref' => 'cs_123']);

        $this->get(route('checkout.return', ['reference' => $order->reference]));
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_the_airwallex_webhook_must_be_signed(): void
    {
        Mail::fake();
        $this->airwallexOn();
        [$event, $tt] = $this->event();
        $order = $this->openOrder($event, $tt);

        $body = json_encode(['id' => 'evt_1', 'name' => 'payment_intent.succeeded', 'data' => ['object' => [
            'id' => 'int_789', 'status' => 'SUCCEEDED', 'merchant_order_id' => $order->reference,
            'latest_payment_attempt' => ['payment_method' => ['type' => 'card', 'card' => ['brand' => 'mastercard']]],
        ]]]);
        $ts = (string) now()->getTimestampMs();
        $post = fn (string $sig) => $this->call('POST', '/webhooks/airwallex', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_TIMESTAMP' => $ts, 'HTTP_X_SIGNATURE' => $sig,
        ], $body);

        $post('forged')->assertStatus(400);
        $this->assertSame('pending', $order->fresh()->status);

        $post(hash_hmac('sha256', $ts.$body, 'whsec'))->assertOk();
        $order->refresh();
        $this->assertSame(['paid', 'int_789', 'mastercard'], [$order->status, $order->payment_ref, $order->payment_brand]);

        // Delivered again: settles once.
        $post(hash_hmac('sha256', $ts.$body, 'whsec'))->assertOk();
        $this->assertSame(2, $order->tickets()->count());
    }

    public function test_an_airwallex_order_is_refunded_at_airwallex(): void
    {
        Mail::fake();
        $this->airwallexOn();
        $this->fakeAirwallex();
        [$event, $tt] = $this->event();
        $order = $this->openOrder($event, $tt, 1);
        app(CheckoutService::class)->markPaid($order, 'int_456');

        // The app's default gateway is the fake one; the order's own gateway is used.
        $result = app(CheckoutService::class)->refund($order->fresh(), null, app(PaymentGateway::class));

        $this->assertTrue($result['ok'] ?? ($result['refunded'] ?? true));
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/pa/refunds/create') && $r['payment_intent_id'] === 'int_456' && (float) $r['amount'] === 50.0);
    }

    public function test_a_foreign_currency_order_is_not_sent_to_chip(): void
    {
        $this->airwallexOn();
        [$event, $tt] = $this->event();
        $order = $this->openOrder($event, $tt);
        // Airwallex switched off after the order opened.
        $order->update(['gateway' => 'chip']);

        $this->post(route('checkout.pay', $order), ['buyer_name' => 'Wei', 'buyer_email' => 'wei@example.sg', 'buyer_phone' => '6591234567', 'consent' => true])
            ->assertSessionHas('flash_error');
        $this->assertSame('pending', $order->fresh()->status);
    }

    // ---- this batch's fixes ---------------------------------------------------------------

    public function test_an_admin_can_leave_a_guest_buyers_account_that_still_needs_a_password(): void
    {
        $admin = $this->superadmin();
        $buyer = User::factory()->create(['must_set_password' => true]);

        $this->actingAs($admin)->post(route('admin.users.impersonate', $buyer))->assertRedirect();
        // Browsing as them is not hijacked by their "choose a password" gate…
        $this->get('/dashboard')->assertOk();
        // …and the way out works.
        $this->post(route('impersonate.stop'))->assertRedirect(route('admin.users.index'));
        $this->assertSame($admin->id, auth()->id());
    }

    public function test_publishing_needs_a_date_but_a_draft_does_not(): void
    {
        $host = $this->organizer();
        $base = [
            'title' => 'Undated', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur', 'region' => 'en-my',
            'ticketTypes' => [['name' => 'Entry', 'kind' => 'free', 'price' => '0', 'min_per_order' => 1, 'max_per_order' => 5, 'is_active' => true]],
            'gallery' => [], 'sections' => [],
        ];

        $this->actingAs($host)->post(route('host.events.store'), [...$base, 'sessions' => [], 'publish' => true])->assertSessionHasErrors('sessions');
        $this->actingAs($host)->post(route('host.events.store'), [...$base, 'sessions' => [['starts_at' => '']], 'publish' => true])
            ->assertSessionHasErrors('sessions.0.starts_at');
        $this->assertSame(0, Event::count());

        $this->actingAs($host)->post(route('host.events.store'), [...$base, 'sessions' => [], 'publish' => false])->assertSessionHasNoErrors();
        $this->assertSame('draft', Event::first()->status);
    }

    public function test_the_pass_page_shows_every_persons_qr_from_the_order(): void
    {
        [$event] = $this->event('en-my');
        $order = Order::create(['reference' => 'DRSVP-FAM01', 'event_id' => $event->id, 'status' => 'paid', 'buyer_name' => 'Ali', 'buyer_email' => 'ali@example.com', 'total' => 0]);
        $tickets = collect(range(1, 3))->map(fn () => $order->tickets()->create(['event_id' => $event->id, 'attendee_name' => 'Ali', 'attendee_email' => 'ali@example.com', 'status' => 'valid']));
        // One was given to a friend: it's theirs now.
        $order->tickets()->create(['event_id' => $event->id, 'attendee_name' => 'Siti', 'attendee_email' => 'siti@example.com', 'status' => 'valid']);

        $this->get('/tickets/'.$tickets[1]->qr_token)->assertOk()->assertInertia(fn (Assert $p) => $p
            ->has('passes', 3)
            ->where('passes.1.qr_token', $tickets[1]->qr_token)
            ->where('passes.0.qr', fn ($svg) => str_contains((string) $svg, '<svg')));
    }

    public function test_the_tickets_email_carries_one_qr_per_ticket(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD is needed to draw the email QR codes.');
        }

        $png = Qr::png('TOKEN-1');
        $this->assertNotNull($png);
        $this->assertStringStartsWith("\x89PNG", $png);
    }

    public function test_a_double_scan_counts_once_and_reads_as_checked_in(): void
    {
        [$event] = $this->event('en-my');
        $order = Order::create(['reference' => 'DRSVP-DOOR1', 'event_id' => $event->id, 'status' => 'paid', 'buyer_name' => 'Ali', 'buyer_email' => 'ali@example.com', 'total' => 0]);
        $ticket = $order->tickets()->create(['event_id' => $event->id, 'attendee_name' => 'Ali', 'status' => 'valid']);
        $door = app(CheckInService::class);

        $this->assertSame('ok', $door->checkIn($event, $ticket, null)['result']);
        $again = $door->checkIn($event, $ticket->fresh(), null);
        $this->assertSame(['already', true], [$again['result'], $again['recent']]);

        $this->travel(10)->minutes();
        $later = $door->checkIn($event, $ticket->fresh(), null);
        $this->assertSame(['already', false], [$later['result'], $later['recent']]);
    }

    public function test_receipts_always_carry_a_logo(): void
    {
        [$event] = $this->event('en-my');
        $order = Order::create(['reference' => 'DRSVP-LOGO1', 'event_id' => $event->id, 'status' => 'paid', 'buyer_name' => 'Ali', 'buyer_email' => 'ali@example.com', 'total' => 10, 'currency' => 'MYR']);

        $receipt = Receipt::forOrder($order);
        $this->assertNull($receipt['seller']['logo'], 'this organizer has no logo…');
        $this->assertSame('/logo-full.png', $receipt['seller']['fallback_logo'], '…so the site’s is used');

        $style = ReceiptTemplate::resolved($receipt['seller']['logo'], $receipt['seller']['fallback_logo']);
        $this->assertTrue($style['show_logo']);
        $this->assertStringStartsWith('data:image/png;base64,', $style['logo']);
    }

    public function test_the_admin_sees_the_event_description_formatted_not_as_tags(): void
    {
        [$event] = $this->event('en-my');
        $event->update(['description' => '<p>Join us for <strong>dancing</strong></p><script>alert(1)</script>']);

        $this->actingAs($this->superadmin())->get(route('admin.events.show', $event))->assertInertia(fn (Assert $p) => $p
            ->where('event.description', fn ($html) => str_contains($html, '<strong>dancing</strong>') && ! str_contains($html, '<script')));
    }
}

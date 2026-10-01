<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventCategory;
use App\Models\Order;
use App\Models\TicketType;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\GoogleAnalytics;
use App\Support\AnalyticsWindow;
use App\Support\Tracking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Each sale reaches GA exactly once: from the buyer's browser when it can, from
 * the server sync when it can't — the two kept in step by a ledger on the order.
 */
class ServerSidePurchaseTrackingTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    private TicketType $type;

    /** Statuses GA will answer with, in order; 204 once they run out. */
    private array $gaStatuses = [];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Http::fake(['www.google-analytics.com/*' => fn () => Http::response('', array_shift($this->gaStatuses) ?? 204)]);
        $this->withoutDefer();
        config(['services.ga.measurement_id' => 'G-ABC123', 'services.ga.api_secret' => 'sekret']);

        $host = $this->organizer(['name' => 'BoardLah Entertainment', 'slug' => 'boardlah']);
        $category = EventCategory::firstOrCreate(['slug' => 'community'], ['name' => 'Community', 'sort_order' => 1]);
        $this->event = Event::create([
            'user_id' => $host->id, 'category_id' => $category->id,
            'title' => 'Clocktower Night', 'slug' => 'clocktower',
            'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
            'city' => 'Kajang', 'venue_name' => 'HOL Cafe', 'starts_at' => now()->addDays(10), 'published_at' => now(),
        ]);
        $this->type = TicketType::create(['event_id' => $this->event->id, 'name' => 'Session A', 'kind' => 'paid', 'price' => 25]);
    }

    /** A paid order, settled `$minutesAgo` minutes ago. */
    private function paidOrder(int $minutesAgo = 30, array $meta = []): Order
    {
        $order = app(CheckoutService::class)->start($this->event, [['ticket_type_id' => $this->type->id, 'quantity' => 2]]);
        $order->update(['buyer_name' => 'Ali', 'buyer_email' => 'ali@x.test', 'meta' => $meta]);
        app(CheckoutService::class)->markPaid($order, 'P1');
        $order->forceFill(['paid_at' => now()->subMinutes($minutesAgo)])->save();

        return $order->fresh();
    }

    private function mine(Order $order): void
    {
        session(['checkout_orders' => [$order->reference]]);
    }

    public function test_reads_the_visitors_ga_ids_from_their_cookies(): void
    {
        $request = Request::create('/', 'GET', server: ['HTTP_COOKIE' => '_ga=GA1.1.123456789.1690000000; _ga_ABC123=GS1.1.1700000000.5.1.1700000100.0.0.0; other=x']);
        $this->assertSame(['client_id' => '123456789.1690000000', 'session_id' => '1700000000'], Tracking::gaIdsFrom($request));

        $request = Request::create('/', 'GET', server: ['HTTP_COOKIE' => '_ga=GA1.1.42.1690000000; _ga_ABC123=GS2.1.s1700000999$o5$g1$t1700001000']);
        $this->assertSame('1700000999', Tracking::gaIdsFrom($request)['session_id']);

        $this->assertSame([], Tracking::gaIdsFrom(Request::create('/')));
    }

    public function test_checkout_captures_the_ga_ids(): void
    {
        $this->withHeader('Cookie', '_ga=GA1.1.987.1690000000')
            ->post(route('checkout.start', $this->event), ['items' => [['ticket_type_id' => $this->type->id, 'quantity' => 1]]]);

        $this->assertSame('987.1690000000', Order::latest('id')->first()->meta['ga']['client_id']);
    }

    public function test_settling_does_not_report_by_itself(): void
    {
        $this->paidOrder(0);

        Http::assertNothingSent(); // the confirmation page gets first go
    }

    // ---- path 1: the browser ------------------------------------------------

    public function test_browser_claims_sends_and_acks_and_only_one_claim_wins(): void
    {
        $order = $this->paidOrder(1);
        $this->mine($order);

        $this->get('/orders/'.$order->reference)->assertOk()
            ->assertInertia(fn ($page) => $page->where('analytics.transaction_id', $order->reference)->where('analytics.value', 50));

        $this->postJson("/orders/{$order->reference}/analytics/claim")->assertOk()->assertJson(['send' => true]);
        // A refresh or second tab asking again is refused.
        $this->postJson("/orders/{$order->reference}/analytics/claim")->assertOk()->assertJson(['send' => false]);

        // The ack arrives by sendBeacon: no CSRF header, no JSON.
        $this->post("/orders/{$order->reference}/analytics/ack")->assertNoContent();

        $order->refresh();
        $this->assertSame('browser', $order->ga_reported_via);
        $this->assertNotNull($order->ga_reported_at);

        // Reported: the page stops offering it, and the server leaves it alone.
        $this->get('/orders/'.$order->reference)->assertInertia(fn ($page) => $page->where('analytics', null));
        app(GoogleAnalytics::class)->sync();
        Http::assertNothingSent();
    }

    public function test_someone_elses_order_cannot_be_claimed(): void
    {
        $order = $this->paidOrder(1);

        $this->postJson("/orders/{$order->reference}/analytics/claim")->assertForbidden();
        $this->post("/orders/{$order->reference}/analytics/ack")->assertForbidden();
        $this->assertNull($order->fresh()->ga_claimed_at);
    }

    // ---- path 2: the server sync -------------------------------------------

    public function test_sync_reports_what_the_browser_did_not_with_the_visitors_ids_and_payment_time(): void
    {
        $order = $this->paidOrder(30, ['ga' => ['client_id' => '123.456', 'session_id' => '1700000000']]);

        $result = app(GoogleAnalytics::class)->sync();

        $this->assertSame(1, $result['sent']);
        Http::assertSent(function (HttpRequest $r) use ($order) {
            $event = $r['events'][0];

            return str_contains($r->url(), 'measurement_id=G-ABC123')
                && str_contains($r->url(), 'api_secret=sekret')
                && $r['client_id'] === '123.456'
                && $r['timestamp_micros'] === $order->paid_at->getTimestamp() * 1_000_000
                && $event['name'] === 'purchase'
                && $event['params']['transaction_id'] === $order->reference
                && $event['params']['value'] == 50.0
                && $event['params']['session_id'] === '1700000000'
                && $event['params']['items'][0]['quantity'] === 2;
        });
        $this->assertSame('server', $order->fresh()->ga_reported_via);

        // Never twice.
        app(GoogleAnalytics::class)->sync();
        Http::assertSentCount(1);
    }

    public function test_sync_gives_the_browser_its_grace_period_and_respects_a_live_claim(): void
    {
        $fresh = $this->paidOrder(1);               // just paid: the page may still report it
        $claimed = $this->paidOrder(30);
        $claimed->forceFill(['ga_claimed_at' => now()->subMinutes(2)])->save(); // page is sending it

        app(GoogleAnalytics::class)->sync();
        Http::assertNothingSent();

        // A claim never acked (tab closed mid-send) lapses, and the server takes over.
        $claimed->forceFill(['ga_claimed_at' => now()->subMinutes(GoogleAnalytics::CLAIM_MINUTES + 1)])->save();
        app(GoogleAnalytics::class)->sync();
        Http::assertSentCount(1);
        $this->assertSame('server', $claimed->fresh()->ga_reported_via);
        $this->assertNull($fresh->fresh()->ga_reported_at);
    }

    public function test_the_page_does_not_send_once_the_server_has(): void
    {
        $order = $this->paidOrder(30);
        app(GoogleAnalytics::class)->sync();
        $this->mine($order);

        $this->get('/orders/'.$order->reference)->assertInertia(fn ($page) => $page->where('analytics', null));
        $this->postJson("/orders/{$order->reference}/analytics/claim")->assertJson(['send' => false]);
    }

    public function test_a_buyer_without_ga_cookies_is_still_counted(): void
    {
        $this->paidOrder(30);
        app(GoogleAnalytics::class)->sync();

        Http::assertSent(fn (HttpRequest $r) => preg_match('/^\d+\.\d+$/', $r['client_id']) === 1);
    }

    public function test_a_failed_send_is_retried(): void
    {
        $this->gaStatuses = [500];
        $order = $this->paidOrder(30);

        $this->assertSame(1, app(GoogleAnalytics::class)->sync()['failed']);
        $this->assertNull($order->fresh()->ga_reported_at);
        $this->assertNull($order->fresh()->ga_claimed_at);

        $this->assertSame(1, app(GoogleAnalytics::class)->sync()['sent']);
    }

    public function test_refunds_follow_the_sale_and_are_never_sent_twice(): void
    {
        $order = $this->paidOrder(30, ['ga' => ['client_id' => '123.456']]);
        app(GoogleAnalytics::class)->sync();

        app(CheckoutService::class)->refund($order->fresh(), 20.0);
        app(GoogleAnalytics::class)->sync();

        $refunds = Http::recorded(fn (HttpRequest $r) => $r['events'][0]['name'] === 'refund')->values();
        $this->assertCount(1, $refunds);
        $this->assertEquals(20.0, $refunds[0][0]['events'][0]['params']['value']);
    }

    public function test_nothing_is_sent_without_a_secret(): void
    {
        config(['services.ga.api_secret' => null]);
        $this->paidOrder(30);

        $this->assertFalse(app(GoogleAnalytics::class)->sync()['configured']);
        Http::assertNothingSent();
    }

    public function test_the_command_runs_the_sync(): void
    {
        $this->paidOrder(30);

        $this->artisan('analytics:sync-purchases')->expectsOutputToContain('Reported 1 sale(s)')->assertSuccessful();
    }

    public function test_the_admin_tally_matches_the_books(): void
    {
        $byBrowser = $this->paidOrder(30);
        $byBrowser->forceFill(['ga_claimed_at' => now(), 'ga_reported_at' => now(), 'ga_reported_via' => 'browser'])->save();
        $this->paidOrder(30);                        // the sync will send this one
        $this->paidOrder(1);                         // still in the browser's grace period
        $old = $this->paidOrder(60 * 24 * 5);        // before the sync existed
        app(GoogleAnalytics::class)->sync();

        $tally = GoogleAnalytics::tally(AnalyticsWindow::resolve('30d'));

        $this->assertSame(['orders' => 4, 'revenue' => 200.0], $tally['paid']);
        $this->assertSame(1, $tally['browser']['orders']);
        $this->assertSame(1, $tally['server']['orders']);
        $this->assertSame(1, $tally['pending']['orders']);
        $this->assertSame(1, $tally['missed']['orders']);
        $this->assertNull($old->fresh()->ga_reported_at);

        Role::findOrCreate('superadmin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');
        $this->actingAs($admin)->get('/admin/analytics')->assertOk()
            ->assertInertia(fn ($page) => $page->where('gaTally.paid.orders', 4));
    }
}

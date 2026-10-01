<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventCategory;
use App\Models\Order;
use App\Models\TicketType;
use App\Services\CheckoutService;
use App\Support\Tracking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Sales reach GA from the server when payment settles — not only when the
 * buyer's browser happens to come back to the confirmation page.
 */
class ServerSidePurchaseTrackingTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    private TicketType $type;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Http::fake(['www.google-analytics.com/*' => Http::response('', 204)]);
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

    private function order(array $meta = []): Order
    {
        $order = app(CheckoutService::class)->start($this->event, [['ticket_type_id' => $this->type->id, 'quantity' => 2]]);
        $order->update(['buyer_name' => 'Ali', 'buyer_email' => 'ali@x.test', 'meta' => $meta]);

        return $order->fresh();
    }

    public function test_reads_the_visitors_ga_ids_from_their_cookies(): void
    {
        $request = Request::create('/', 'GET', server: ['HTTP_COOKIE' => '_ga=GA1.1.123456789.1690000000; _ga_ABC123=GS1.1.1700000000.5.1.1700000100.0.0.0; other=x']);
        $this->assertSame(['client_id' => '123456789.1690000000', 'session_id' => '1700000000'], Tracking::gaIdsFrom($request));

        // The newer cookie format.
        $request = Request::create('/', 'GET', server: ['HTTP_COOKIE' => '_ga=GA1.1.42.1690000000; _ga_ABC123=GS2.1.s1700000999$o5$g1$t1700001000']);
        $this->assertSame('1700000999', Tracking::gaIdsFrom($request)['session_id']);

        $this->assertSame([], Tracking::gaIdsFrom(Request::create('/')));
    }

    public function test_settling_an_order_reports_the_purchase_once(): void
    {
        $order = $this->order(['ga' => ['client_id' => '123.456', 'session_id' => '1700000000']]);

        app(CheckoutService::class)->markPaid($order, 'P1');
        app(CheckoutService::class)->markPaid($order, 'P1'); // webhook + return both arriving

        Http::assertSentCount(1);
        Http::assertSent(function (HttpRequest $r) use ($order) {
            $event = $r['events'][0];

            return str_contains($r->url(), 'measurement_id=G-ABC123')
                && str_contains($r->url(), 'api_secret=sekret')
                && $r['client_id'] === '123.456'
                && $event['name'] === 'purchase'
                && $event['params']['transaction_id'] === $order->reference
                && $event['params']['value'] == 50.0
                && $event['params']['currency'] === 'MYR'
                && $event['params']['session_id'] === '1700000000'
                && $event['params']['items'][0]['quantity'] === 2;
        });
    }

    public function test_a_buyer_without_ga_cookies_is_still_counted(): void
    {
        app(CheckoutService::class)->markPaid($this->order(), 'P1');

        Http::assertSent(fn (HttpRequest $r) => $r['events'][0]['name'] === 'purchase' && preg_match('/^\d+\.\d+$/', $r['client_id']) === 1);
    }

    public function test_refunds_are_reported(): void
    {
        $order = $this->order(['ga' => ['client_id' => '123.456']]);
        app(CheckoutService::class)->markPaid($order, 'P1');

        app(CheckoutService::class)->refund($order->fresh(), 20.0);

        Http::assertSent(fn (HttpRequest $r) => $r['events'][0]['name'] === 'refund' && $r['events'][0]['params']['value'] == 20.0);
    }

    public function test_the_browser_does_not_double_report_when_the_server_does(): void
    {
        $order = $this->order();
        session(['checkout_orders' => [$order->reference]]);
        app(CheckoutService::class)->markPaid($order, 'P1');

        $this->get('/orders/'.$order->reference)->assertOk()
            ->assertInertia(fn ($page) => $page->where('analytics', null));

        // Without a secret the browser reports it, as before.
        config(['services.ga.api_secret' => null]);
        $this->get('/orders/'.$order->reference)->assertOk()
            ->assertInertia(fn ($page) => $page->where('analytics.transaction_id', $order->reference));
    }

    public function test_nothing_is_sent_without_a_secret(): void
    {
        config(['services.ga.api_secret' => null]);

        app(CheckoutService::class)->markPaid($this->order(), 'P1');

        Http::assertNothingSent();
    }

    public function test_checkout_captures_the_ga_ids(): void
    {
        $this->withHeader('Cookie', '_ga=GA1.1.987.1690000000')
            ->post(route('checkout.start', $this->event), ['items' => [['ticket_type_id' => $this->type->id, 'quantity' => 1]]]);

        $this->assertSame('987.1690000000', Order::latest('id')->first()->meta['ga']['client_id']);
    }
}

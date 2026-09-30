<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventCategory;
use App\Models\EventPhoto;
use App\Models\Order;
use App\Models\TicketType;
use App\Models\User;
use App\Services\CheckoutService;
use App\Support\GoogleAnalytics;
use App\Support\Impersonation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The batch of adjustments asked for after the first production week:
 * ticket-type visibility on the revenue page, GA scoping and ecommerce events,
 * rich-text descriptions, "view as", and pushing gallery photos to a profile.
 */
class AdjustmentsTest extends TestCase
{
    use RefreshDatabase;

    private function eventWithTwoSessions(): Event
    {
        $host = $this->organizer(['name' => 'BoardLah Entertainment']);
        $category = EventCategory::firstOrCreate(['slug' => 'community'], ['name' => 'Community', 'sort_order' => 1]);

        $event = Event::create([
            'user_id' => $host->id, 'category_id' => $category->id,
            'title' => 'Blood on the Clocktower Session', 'slug' => 'botc',
            'description' => 'A social-deduction game night.',
            'status' => 'published', 'visibility' => 'public',
            'timezone' => 'Asia/Kuala_Lumpur', 'city' => 'Kajang', 'venue_name' => 'HOL Cafe',
            'starts_at' => now()->addDays(10),
        ]);

        TicketType::create(['event_id' => $event->id, 'name' => 'Session A - For Beginners', 'kind' => 'paid', 'price' => 20, 'sort_order' => 1]);
        TicketType::create(['event_id' => $event->id, 'name' => 'Session B - For Experienced Players', 'kind' => 'paid', 'price' => 20, 'sort_order' => 2]);

        return $event;
    }

    private function buy(Event $event, TicketType $type, int $quantity = 1): Order
    {
        $order = app(CheckoutService::class)->start($event, [['ticket_type_id' => $type->id, 'quantity' => $quantity]]);
        $order->update(['buyer_name' => 'Azfar Shamin', 'buyer_email' => 'azfar@example.com']);
        app(CheckoutService::class)->markPaid($order, 'TEST-REF', ['method' => 'fpx', 'brand' => 'maybank2u']);

        return $order->fresh();
    }

    // ---- 1. Which ticket type the money came from ---------------------------

    public function test_the_revenue_page_names_the_ticket_type_on_every_invoice(): void
    {
        $event = $this->eventWithTwoSessions();
        [$a, $b] = $event->ticketTypes()->orderBy('sort_order')->get()->all();

        $first = $this->buy($event, $a, 2);
        $second = $this->buy($event, $b);

        // The list orders by paid_at, and both settle inside the same second in
        // a test, so assert on the invoices by reference rather than position.
        $response = $this->actingAs($event->user)
            ->get(route('host.invoices.event', $event))
            ->assertOk();

        $rows = collect($response->viewData('page')['props']['orders']['data'])->keyBy('reference');

        $this->assertSame('Session A - For Beginners', $rows[$first->reference]['types'][0]['name']);
        $this->assertSame(2, $rows[$first->reference]['types'][0]['quantity']);
        $this->assertSame('Session B - For Experienced Players', $rows[$second->reference]['types'][0]['name']);

        // …and a revenue split across the event, biggest first. Compared
        // numerically: a whole-ringgit total serialises without its zero
        // fraction, so a strict === against 40.0 would fail on the type alone.
        $response->assertInertia(fn ($page) => $page
            ->component('host/invoices/event')
            ->has('byType', 2)
            ->where('byType.0.name', 'Session A - For Beginners')
            ->where('byType.0.sold', 2));

        $byType = $response->viewData('page')['props']['byType'];
        $this->assertEqualsWithDelta(40, $byType[0]['gross'], 0.001);
        $this->assertEqualsWithDelta(20, $byType[1]['gross'], 0.001);
    }

    // ---- 2. Google Analytics ------------------------------------------------

    public function test_analytics_is_kept_off_the_back_office(): void
    {
        config(['services.ga.measurement_id' => 'G-TEST']);
        $this->app->detectEnvironment(fn () => 'production');

        $public = ['/en-my/', '/en-my/all', '/en-my/e/botc'];
        $private = ['/admin/overview', '/host/events', '/dashboard', '/settings/profile', '/my/tickets', '/login'];

        foreach ($public as $path) {
            $this->assertTrue(
                GoogleAnalytics::shouldTrack(Request::create($path)),
                "{$path} is a public page and should be measured.",
            );
        }

        foreach ($private as $path) {
            $this->assertFalse(
                GoogleAnalytics::shouldTrack(Request::create($path)),
                "{$path} is the back office and must not reach the GA property.",
            );
        }
    }

    public function test_the_checkout_funnel_still_reports_because_a_purchase_happens_there(): void
    {
        config(['services.ga.measurement_id' => 'G-TEST']);
        $this->app->detectEnvironment(fn () => 'production');

        foreach (['/checkout/DRSVP-ABC', '/orders/DRSVP-ABC'] as $path) {
            $this->assertTrue(GoogleAnalytics::shouldTrack(Request::create($path)));
        }
    }

    public function test_a_settled_order_produces_a_purchase_payload(): void
    {
        $event = $this->eventWithTwoSessions();
        $order = $this->buy($event, $event->ticketTypes()->first(), 2);

        $payload = GoogleAnalytics::purchasePayload($order);

        $this->assertSame($order->reference, $payload['transaction_id']);
        $this->assertSame(40.0, $payload['value']);
        $this->assertSame('MYR', $payload['currency']);
        $this->assertSame('Session A - For Beginners', $payload['items'][0]['item_name']);
        $this->assertSame(2, $payload['items'][0]['quantity']);
        $this->assertSame('Community', $payload['items'][0]['item_category']);
    }

    public function test_a_pending_order_is_not_reported_as_a_purchase(): void
    {
        $event = $this->eventWithTwoSessions();
        $order = app(CheckoutService::class)->start($event, [['ticket_type_id' => $event->ticketTypes()->first()->id, 'quantity' => 1]]);

        $this->assertNull(GoogleAnalytics::purchasePayload($order));
        $this->assertNotNull(GoogleAnalytics::checkoutPayload($order));
    }

    public function test_the_confirmation_page_hands_the_purchase_event_to_the_browser(): void
    {
        $event = $this->eventWithTwoSessions();
        $order = $this->buy($event, $event->ticketTypes()->first());

        // As the ORGANIZER: checkout provisions a buyer account with
        // must_set_password set, and acting as that one would be bounced to
        // /set-password before the confirmation page ever renders.
        $this->actingAs($event->user)
            ->get(route('checkout.confirmation', $order))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('analytics.transaction_id', $order->reference));
    }

    // ---- 3. Rich-text descriptions -----------------------------------------

    public function test_an_organizer_can_bold_words_in_a_description_but_not_inject_a_script(): void
    {
        $host = $this->organizer();
        EventCategory::firstOrCreate(['slug' => 'community'], ['name' => 'Community', 'sort_order' => 1]);

        $this->actingAs($host)->post(route('host.events.store'), [
            'title' => 'Formatted Night',
            'description' => '<p>Come for the <strong>free pizza</strong>.</p><script>alert(1)</script><p onclick="steal()">Bring friends</p>',
            'visibility' => 'public',
            'timezone' => 'Asia/Kuala_Lumpur',
            'sessions' => [['starts_at' => now()->addDays(5)->format('Y-m-d H:i:s')]],
            'ticketTypes' => [['name' => 'Free', 'kind' => 'free', 'price' => 0, 'min_per_order' => 1, 'max_per_order' => 5]],
        ])->assertSessionHasNoErrors();

        $description = Event::where('title', 'Formatted Night')->value('description');

        $this->assertStringContainsString('<strong>free pizza</strong>', $description);
        $this->assertStringNotContainsString('<script', $description);
        $this->assertStringNotContainsString('onclick', $description);
    }

    // ---- 4. "View as" -------------------------------------------------------

    public function test_a_superadmin_can_view_the_site_as_an_organizer_and_come_back(): void
    {
        $admin = User::factory()->create();
        Role::findOrCreate('superadmin', 'web');
        $admin->assignRole('superadmin');

        $host = $this->organizer(['name' => 'BoardLah Entertainment']);

        $this->actingAs($admin)
            ->post(route('admin.users.impersonate', $host))
            ->assertRedirect(route('host.events.index'));

        $this->assertAuthenticatedAs($host);
        $this->assertSame($admin->id, session(Impersonation::KEY));

        // The banner is shared on every page while it lasts.
        $this->get('/dashboard')->assertInertia(fn ($page) => $page
            ->where('impersonating.viewing', 'BoardLah Entertainment')
            ->where('impersonating.role', 'organizer')
            ->where('impersonating.actor', $admin->name));

        $this->post(route('impersonate.stop'))->assertRedirect(route('admin.users.index'));

        $this->assertAuthenticatedAs($admin);
        $this->assertNull(session(Impersonation::KEY));
    }

    public function test_view_as_is_refused_for_non_superadmins_and_for_other_superadmins(): void
    {
        Role::findOrCreate('superadmin', 'web');

        $admin = User::factory()->create();
        $admin->assignRole('superadmin');
        $otherAdmin = User::factory()->create();
        $otherAdmin->assignRole('superadmin');
        $staff = $this->organizer();
        $buyer = User::factory()->create();

        // Sideways into another superadmin: refused.
        $this->actingAs($admin)->post(route('admin.users.impersonate', $otherAdmin));
        $this->assertAuthenticatedAs($admin);

        // A disabled account: refused.
        $buyer->forceFill(['disabled_at' => now()])->save();
        $this->actingAs($admin)->post(route('admin.users.impersonate', $buyer));
        $this->assertAuthenticatedAs($admin);

        // A non-superadmin cannot reach the route at all.
        $this->actingAs($staff)->post(route('admin.users.impersonate', $buyer))->assertForbidden();
    }

    public function test_stopping_without_an_impersonation_logs_out_rather_than_doing_nothing(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('impersonate.stop'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    // ---- 5. Gallery photos onto the organizer profile -----------------------

    public function test_an_organizer_can_push_gallery_images_into_their_profile_album(): void
    {
        $event = $this->eventWithTwoSessions();
        $event->update(['gallery' => ['/storage/cms/one.jpg', '/storage/cms/two.jpg', '/storage/cms/three.jpg']]);

        $this->actingAs($event->user)
            ->get(route('host.events.photos', $event))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('galleryOptions', 3));

        $this->actingAs($event->user)->post(route('host.events.photos.from-gallery', $event), [
            'paths' => ['/storage/cms/one.jpg', '/storage/cms/two.jpg'],
        ]);

        $this->assertSame(2, EventPhoto::where('event_id', $event->id)->count());

        // Already-added images drop out of the picker rather than being offered twice.
        $this->actingAs($event->user)
            ->get(route('host.events.photos', $event))
            ->assertInertia(fn ($page) => $page->has('galleryOptions', 1));

        // …and they now show on the public organizer profile.
        $this->get('/en-my/o/'.$event->user->ensureSlug())
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('photos', 2));
    }

    public function test_only_images_on_that_event_can_be_pushed_into_the_album(): void
    {
        $event = $this->eventWithTwoSessions();
        $event->update(['gallery' => ['/storage/cms/mine.jpg']]);

        $this->actingAs($event->user)->post(route('host.events.photos.from-gallery', $event), [
            'paths' => ['https://evil.example/tracker.png'],
        ]);

        $this->assertSame(0, EventPhoto::where('event_id', $event->id)->count());
    }

    // ---- 6. A compulsory contact email on the application -------------------

    public function test_an_application_without_an_email_or_phone_is_rejected(): void
    {
        $user = $this->organizer();

        $this->actingAs($user)->post(route('host.apply.submit'), [
            'business_name' => 'No Contact Co',
        ])->assertSessionHasErrors(['email', 'phone']);

        $this->assertNull($user->fresh()->organizerProfile?->business_name);
    }
}

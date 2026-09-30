<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventCategory;
use App\Models\EventPhoto;
use App\Models\Order;
use App\Models\OrganizerProfile;
use App\Models\TicketType;
use App\Models\User;
use App\Services\CheckoutService;
use App\Support\Receipt;
use App\Support\ReceiptTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Two things an organizer and a buyer each see, and one number neither should.
 */
class OrganizerPhotosAndFeePrivacyTest extends TestCase
{
    use RefreshDatabase;

    private function host(): User
    {
        $host = $this->organizer(['name' => 'BoardLah Entertainment', 'slug' => 'boardlah']);
        OrganizerProfile::create(['user_id' => $host->id, 'business_name' => 'BoardLah Entertainment', 'status' => 'approved']);

        return $host;
    }

    private function event(User $host, string $slug, array $gallery = []): Event
    {
        $category = EventCategory::firstOrCreate(['slug' => 'community'], ['name' => 'Community', 'sort_order' => 1]);

        $event = Event::create([
            'user_id' => $host->id, 'category_id' => $category->id,
            'title' => 'Event '.$slug, 'slug' => $slug,
            'description' => 'x', 'status' => 'published', 'visibility' => 'public',
            'timezone' => 'Asia/Kuala_Lumpur', 'city' => 'Kajang', 'venue_name' => 'HOL Cafe',
            'starts_at' => now()->addDays(10), 'published_at' => now(),
            'gallery' => $gallery,
        ]);

        TicketType::create(['event_id' => $event->id, 'name' => 'Standard', 'kind' => 'paid', 'price' => 100]);

        return $event;
    }

    // ---- Organizer profile photos fill themselves --------------------------

    public function test_every_event_gallery_shows_on_the_profile_without_being_copied_across(): void
    {
        $host = $this->host();
        $this->event($host, 'one', ['/storage/cms/a.jpg', '/storage/cms/b.jpg']);
        $this->event($host, 'two', ['/storage/cms/c.jpg']);

        $this->get('/en-my/o/'.$host->slug)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('photos', 3)
                ->where('organizer.photos_count', 3));
    }

    public function test_the_after_event_album_and_the_promo_gallery_appear_together(): void
    {
        $host = $this->host();
        $event = $this->event($host, 'one', ['/storage/cms/promo.jpg']);
        EventPhoto::create(['event_id' => $event->id, 'path' => '/storage/cms/night.jpg', 'uploaded_by' => $host->id]);

        $response = $this->get('/en-my/o/'.$host->slug)->assertOk();
        $paths = collect($response->viewData('page')['props']['photos'])->pluck('path')->all();

        $this->assertContains('/storage/cms/promo.jpg', $paths);
        $this->assertContains('/storage/cms/night.jpg', $paths);
    }

    public function test_an_image_in_both_places_is_only_shown_once(): void
    {
        $host = $this->host();
        $event = $this->event($host, 'one', ['/storage/cms/same.jpg']);
        // As the old "copy to profile" button would have left it.
        EventPhoto::create(['event_id' => $event->id, 'path' => '/storage/cms/same.jpg', 'uploaded_by' => $host->id]);

        $this->get('/en-my/o/'.$host->slug)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('photos', 1));
    }

    public function test_another_organizers_gallery_never_leaks_onto_this_profile(): void
    {
        $host = $this->host();
        $this->event($host, 'mine', ['/storage/cms/mine.jpg']);

        $other = $this->organizer(['name' => 'Someone Else', 'slug' => 'someone-else']);
        $this->event($other, 'theirs', ['/storage/cms/theirs.jpg']);

        $response = $this->get('/en-my/o/'.$host->slug)->assertOk();
        $paths = collect($response->viewData('page')['props']['photos'])->pluck('path')->all();

        $this->assertSame(['/storage/cms/mine.jpg'], $paths);
    }

    public function test_the_manual_copy_endpoint_is_gone(): void
    {
        // It was replaced by the automatic merge, so it should no longer be
        // routable at all.
        $this->assertFalse(
            Route::has('host.events.photos.from-gallery'),
            'The manual copy endpoint should have been removed with the picker.',
        );
    }

    // ---- The platform fee is the platform's business -----------------------

    private User $buyer;

    private function paidOrder(): Order
    {
        // A real commission, so the assertions below are not passing on a zero.
        config(['droprsvp.platform_fee_percent' => 10, 'droprsvp.platform_fee_flat' => 2]);

        $host = $this->host();
        $event = $this->event($host, 'fees');

        // The buyer exists first, so checkout links the order to them instead
        // of provisioning a guest account (which is flagged must_set_password
        // and would bounce every later request to /set-password).
        $this->buyer = User::factory()->create(['name' => 'Azfar', 'email' => 'azfar@example.com']);

        $order = app(CheckoutService::class)->start($event, [
            ['ticket_type_id' => $event->ticketTypes()->first()->id, 'quantity' => 1],
        ], $this->buyer->id);
        $order->update(['buyer_name' => 'Azfar', 'buyer_email' => $this->buyer->email]);
        app(CheckoutService::class)->markPaid($order, 'TEST');

        return $order->fresh();
    }

    public function test_the_order_records_the_commission_even_though_nobody_bills_the_buyer_for_it(): void
    {
        $order = $this->paidOrder();

        $this->assertSame(10.0, (float) $order->fees, 'The commission is still recorded on the order.');
        $this->assertSame(100.0, (float) $order->total, 'And is not added to what the buyer pays.');
    }

    public function test_the_buyer_never_sees_the_platform_fee_on_their_receipt(): void
    {
        $order = $this->paidOrder();

        $response = $this->actingAs($this->buyer)->get(route('account.orders.receipt', $order))->assertOk();

        $response->assertDontSee('Platform fee', false);
        // Not merely hidden — absent from the payload, so it is not readable
        // from the page props either.
        $response->assertInertia(fn ($page) => $page->missing('receipt.fees'));
    }

    public function test_the_platform_fee_is_not_in_the_downloaded_pdf_either(): void
    {
        $order = $this->paidOrder();

        // The endpoint works…
        $this->actingAs($this->buyer)
            ->get(route('account.orders.receipt.pdf', $order))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        // …and the template it is built from has no fee line. Asserted on the
        // rendered HTML rather than the PDF bytes: dompdf compresses its text
        // streams, so grepping the binary would pass whether the line was
        // there or not.
        $html = view('receipts.pdf', [
            'receipt' => Receipt::forOrder($order),
            'style' => ReceiptTemplate::resolved(null),
        ])->render();

        $this->assertStringNotContainsStringIgnoringCase('Platform fee', $html);
        // The lines that SHOULD be there still are, so this is not passing
        // because the totals block failed to render at all.
        $this->assertStringContainsString('Subtotal', $html);
        $this->assertStringContainsString('Total', $html);
        $this->assertStringContainsString(number_format((float) $order->total, 2), $html);
    }

    public function test_the_checkout_page_does_not_ship_the_commission_to_the_browser(): void
    {
        config(['droprsvp.platform_fee_percent' => 10, 'droprsvp.platform_fee_flat' => 2]);

        $host = $this->host();
        $event = $this->event($host, 'checkout');

        $this->post(route('checkout.start', $event), [
            'items' => [['ticket_type_id' => $event->ticketTypes()->first()->id, 'quantity' => 1]],
        ]);

        $order = Order::latest('id')->first();

        $this->get(route('checkout.show', $order))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->missing('order.fees'));
    }

    public function test_the_organizer_viewing_an_attendee_invoice_does_not_see_it_either(): void
    {
        $order = $this->paidOrder();

        // The receipt is the BUYER's document; it reads the same whoever opens
        // it. The organizer's own cost lives in their payout balance.
        $this->actingAs($order->event->user)
            ->get(route('account.orders.receipt', $order))
            ->assertOk()
            ->assertDontSee('Platform fee', false);
    }

    public function test_an_admin_can_still_account_for_the_commission(): void
    {
        $order = $this->paidOrder();

        $admin = User::factory()->create();
        Role::findOrCreate('superadmin', 'web');
        $admin->assignRole('superadmin');

        // Not on the buyer's receipt — in the finance ledger, where the
        // platform's own revenue belongs and is separated from GMV.
        $this->actingAs($admin)->get(route('admin.finance.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('kpis.platform_revenue', fn ($v) => (float) $v > 0));
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }
}

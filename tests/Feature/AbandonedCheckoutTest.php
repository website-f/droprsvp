<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventCategory;
use App\Models\Order;
use App\Models\TicketType;
use App\Models\User;
use App\Services\CheckoutService;
use App\Support\AnalyticsWindow;
use App\Support\CheckoutFunnel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * People who started buying and did not finish — counted, and listed with
 * enough detail to follow up by email.
 */
class AbandonedCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    private TicketType $type;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

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

    /** Start a checkout `$minutesAgo` minutes ago, optionally filling in the form. */
    private function checkout(int $minutesAgo, array $details = [], ?int $userId = null, int $qty = 1): Order
    {
        $order = app(CheckoutService::class)->start($this->event, [['ticket_type_id' => $this->type->id, 'quantity' => $qty]], $userId);
        if ($details) {
            $order->update($details);
        }
        $order->forceFill(['created_at' => now()->subMinutes($minutesAgo)])->save();

        return $order->fresh();
    }

    private function window(): array
    {
        return AnalyticsWindow::resolve('30d');
    }

    public function test_stages_people_and_recovery(): void
    {
        $member = User::factory()->create(['name' => 'Member Mia', 'email' => 'mia@x.test', 'phone' => '0123456789']);

        // Guest who left at checkout without typing anything: counted, not contactable.
        $this->checkout(120);
        // Member who left at checkout: their account email makes them contactable.
        $this->checkout(90, [], $member->id, 2);
        // Filled the form twice, never paid: one person, two attempts.
        $this->checkout(300, ['buyer_name' => 'Ali', 'buyer_email' => 'ali@x.test', 'buyer_phone' => '0111']);
        $this->checkout(60, ['buyer_name' => 'Ali', 'buyer_email' => 'ALI@x.test', 'buyer_phone' => '0111']);
        // Filled the form, abandoned — then came back and paid: recovered.
        $this->checkout(200, ['buyer_name' => 'Siti', 'buyer_email' => 'siti@x.test']);
        $paid = $this->checkout(100, ['buyer_name' => 'Siti', 'buyer_email' => 'siti@x.test']);
        app(CheckoutService::class)->markPaid($paid, 'T');
        // Still inside the hold window: in progress, not abandoned.
        $this->checkout(5, ['buyer_name' => 'Now', 'buyer_email' => 'now@x.test']);

        $rows = CheckoutFunnel::rows($this->window(), $this->event->id);
        $open = $rows->where('recovered', false)->keyBy(fn ($r) => $r['email'] ?? 'guest');

        $this->assertSame('details', $open['ali@x.test']['stage']);
        $this->assertSame(2, $open['ali@x.test']['attempts']);
        $this->assertSame('checkout', $open['ali@x.test']['consent']);

        $this->assertSame('started', $open['mia@x.test']['stage']);
        $this->assertSame('Member Mia', $open['mia@x.test']['name']);
        $this->assertSame(2, $open['mia@x.test']['tickets']);
        $this->assertSame(50.0, $open['mia@x.test']['value']);
        $this->assertSame('account', $open['mia@x.test']['consent']);

        $this->assertNull($open['guest']['email']);
        $this->assertFalse($open->has('now@x.test'));
        $this->assertTrue($rows->firstWhere('email', 'siti@x.test')['recovered']);

        $s = CheckoutFunnel::summary($this->window(), $this->event->id);
        $this->assertSame(7, $s['started']);
        $this->assertSame(1, $s['paid']);
        $this->assertSame(1, $s['in_progress']);
        $this->assertSame(5, $s['abandoned']['orders']);       // every unpaid order past its hold
        $this->assertSame(3, $s['abandoned']['people']);       // guest, Mia, Ali (Siti recovered)
        $this->assertSame(2, $s['abandoned']['reachable']);    // Mia + Ali
        $this->assertSame(1, $s['abandoned']['recovered']);
        $this->assertSame(100.0, $s['abandoned']['lost_value']); // 25 + 50 + 25
    }

    public function test_released_and_free_cancelled_orders(): void
    {
        // Released by orders:release-stale — still abandoned.
        $released = $this->checkout(40, ['buyer_email' => 'gone@x.test']);
        app(CheckoutService::class)->release($released);
        // A free RSVP the guest later cancelled has paid_at — not abandoned.
        $free = $this->checkout(40, ['buyer_email' => 'rsvp@x.test']);
        $free->forceFill(['status' => 'cancelled', 'paid_at' => now(), 'total' => 0])->save();

        $emails = CheckoutFunnel::rows($this->window())->pluck('email')->all();
        $this->assertSame(['gone@x.test'], $emails);
    }

    public function test_superadmin_page_and_edm_export(): void
    {
        Role::findOrCreate('superadmin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');

        $this->checkout(120, ['buyer_name' => 'Ali', 'buyer_email' => 'ali@x.test', 'buyer_phone' => '0111']);

        $this->actingAs($admin)->get('/admin/analytics/abandoned')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/analytics/abandoned')
                ->where('summary.abandoned.people', 1)
                ->where('rows.data.0.email', 'ali@x.test')
                ->where('byEvent.0.slug', 'clocktower'));

        $csv = $this->actingAs($admin)->get('/admin/analytics/abandoned/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('ali@x.test', $csv);
        $this->assertStringContainsString('Filled in details', $csv);

        // The platform page and per-event page carry the funnel too.
        $this->actingAs($admin)->get('/admin/analytics')->assertOk()
            ->assertInertia(fn ($page) => $page->where('funnel.abandoned.people', 1)->has('people.kpis')->has('topOrganizers'));
        $this->actingAs($admin)->get('/admin/analytics/clocktower')->assertOk()
            ->assertInertia(fn ($page) => $page->where('data.funnel.abandoned.people', 1));
    }

    public function test_organizers_cannot_see_it(): void
    {
        $this->actingAs($this->event->user)->get('/admin/analytics/abandoned')->assertForbidden();
    }
}

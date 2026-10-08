<?php

namespace Tests\Feature;

use App\Mail\OrderPlacedAdminMail;
use App\Mail\OrderPlacedOrganizerMail;
use App\Models\Event;
use App\Models\Order;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The organizer hears about their own sales.
 *
 * The platform inbox was told about every booking while the person running the
 * event was not, and only found out by opening their dashboard.
 */
class OrganizerBookingNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $organizer;

    private Event $event;

    private TicketType $ticketType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organizer = $this->organizer(['name' => 'BoardLah', 'email' => 'host@example.test']);
        $this->event = Event::create([
            'user_id' => $this->organizer->id, 'title' => 'Game Night', 'slug' => 'game-night',
            'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
            'starts_at' => now()->addDays(5),
        ]);
        $this->ticketType = $this->event->ticketTypes()->create([
            'name' => 'GA', 'kind' => 'paid', 'price' => 30, 'quantity' => 100,
            'min_per_order' => 1, 'max_per_order' => 10, 'is_active' => true,
        ]);
    }

    /** Buy through the real checkout so settlement runs exactly as in production. */
    private function book(int $quantity = 2, string $email = 'buyer@example.test'): Order
    {
        $this->post(route('checkout.start', $this->event), [
            'items' => [['ticket_type_id' => $this->ticketType->id, 'quantity' => $quantity]],
        ])->assertRedirect();

        $order = Order::latest('id')->firstOrFail();

        $this->post(route('checkout.pay', $order), [
            'buyer_name' => 'Ali', 'buyer_email' => $email, 'buyer_phone' => '0123456789', 'consent' => true,
        ])->assertRedirect();

        $this->get(route('checkout.fake', $order))->assertRedirect();

        return $order->fresh();
    }

    public function test_the_organizer_is_emailed_when_someone_books(): void
    {
        Mail::fake();

        $this->book();

        Mail::assertSent(OrderPlacedOrganizerMail::class, fn (OrderPlacedOrganizerMail $m) => $m->hasTo('host@example.test'));
        // The platform copy still goes out as well.
        Mail::assertSent(OrderPlacedAdminMail::class);
    }

    public function test_the_email_says_who_booked_what_and_what_it_earns(): void
    {
        Mail::fake();

        $order = $this->book();
        $order->forceFill(['fees' => 3.00])->save();

        $mail = new OrderPlacedOrganizerMail($order->fresh()->load(['event', 'items', 'event.user']));
        $rendered = $mail->render();

        $this->assertStringContainsString('2 tickets booked · Game Night', $mail->envelope()->subject);
        $this->assertStringContainsString('Ali', $rendered);
        $this->assertStringContainsString('buyer@example.test', $rendered);
        $this->assertStringContainsString('MYR 60.00', $rendered);  // what the buyer paid
        $this->assertStringContainsString('MYR 57.00', $rendered);  // minus our commission
        $this->assertStringContainsString('/host/events/game-night/orders', $rendered);
        // Replying reaches the person who booked, not the robot.
        $this->assertSame('buyer@example.test', $mail->envelope()->replyTo[0]->address);
    }

    public function test_one_ticket_is_not_called_tickets(): void
    {
        Mail::fake();
        $order = $this->book(1, 'solo@example.test');

        $this->assertStringContainsString(
            '1 ticket booked · Game Night',
            (new OrderPlacedOrganizerMail($order->load(['event', 'items'])))->envelope()->subject,
        );
    }

    public function test_switching_the_toggle_off_stops_the_emails(): void
    {
        Mail::fake();
        $this->organizer->forceFill(['notification_preferences' => ['organizer_sales' => false]])->save();

        $this->book();

        Mail::assertNotSent(OrderPlacedOrganizerMail::class);
        // Their buyer still gets the tickets, and we still get the platform copy.
        Mail::assertSent(OrderPlacedAdminMail::class);
    }

    public function test_a_disabled_organizer_is_not_emailed(): void
    {
        Mail::fake();
        $this->organizer->forceFill(['disabled_at' => now()])->save();

        $this->book();

        Mail::assertNotSent(OrderPlacedOrganizerMail::class);
    }

    public function test_an_organizer_buying_their_own_ticket_is_not_told_about_it(): void
    {
        Mail::fake();

        $this->book(1, 'host@example.test');

        Mail::assertNotSent(OrderPlacedOrganizerMail::class);
    }

    public function test_the_toggle_shows_for_organizers_only(): void
    {
        $this->actingAs($this->organizer)->get('/settings/notifications')->assertOk()
            ->assertInertia(fn ($p) => $p->has('channels.organizer_sales'));

        // An attendee has no events to be booked, so the switch would be noise.
        $this->actingAs(User::factory()->create())->get('/settings/notifications')->assertOk()
            ->assertInertia(fn ($p) => $p->missing('channels.organizer_sales'));
    }

    public function test_an_organizer_can_turn_it_off_from_settings(): void
    {
        $this->actingAs($this->organizer)
            ->patch('/settings/notifications', ['organizer_sales' => false])
            ->assertRedirect();

        $this->assertFalse($this->organizer->fresh()->wantsNotification('organizer_sales'));
    }

    public function test_it_is_on_until_they_turn_it_off(): void
    {
        $this->assertTrue($this->organizer->wantsNotification('organizer_sales'));
    }
}

<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Support\PlatformFee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * A paid ticket may not be priced below the platform commission it carries.
 *
 * Buyers pay the ticket price and nothing else, so the commission comes out of
 * the organizer's takings. On the default RM3 flat fee an RM2 ticket would
 * therefore settle at MINUS one ringgit per sale — the organizer would lose
 * money on every booking, which nobody would notice until payout day.
 *
 * The floor follows the organizer's OWN rate: an admin can move one organizer
 * onto a custom fee, and that moves their minimum with it.
 */
class TicketPriceFloorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('droprsvp.platform_fee_percent', 5);
        Config::set('droprsvp.platform_fee_flat', 3);
    }

    /** @param  array<string,mixed>  $overrides */
    private function payload(array $ticket): array
    {
        return [
            'title' => 'Indie Night',
            'visibility' => 'public',
            'timezone' => 'Asia/Kuala_Lumpur',
            'is_online' => false,
            'venue_name' => 'The Bee',
            'sessions' => [
                ['starts_at' => now()->addDays(5)->toDateTimeString()],
            ],
            'ticketTypes' => [array_merge(
                ['name' => 'GA', 'quantity' => 100, 'min_per_order' => 1, 'max_per_order' => 6, 'is_active' => true],
                $ticket,
            )],
        ];
    }

    public function test_a_paid_ticket_priced_under_the_fee_is_rejected(): void
    {
        $user = $this->organizer();

        $this->actingAs($user)
            ->post('/host/events', $this->payload(['kind' => 'paid', 'price' => 2]))
            ->assertSessionHasErrors('ticketTypes.0.price');

        $this->assertSame(0, Event::count());
    }

    public function test_the_error_says_what_the_organizer_would_actually_receive(): void
    {
        $user = $this->organizer();

        $response = $this->actingAs($user)->post('/host/events', $this->payload(['kind' => 'paid', 'price' => 2]));

        $response->assertSessionHasErrors('ticketTypes.0.price');
        $message = (string) session('errors')->getBag('default')->first('ticketTypes.0.price');

        // The number that matters to them is their own take, not the rate.
        $this->assertStringContainsString('RM3.00', $message);
        $this->assertStringContainsString('-1.00', $message);
    }

    public function test_a_paid_ticket_at_or_above_the_fee_is_accepted(): void
    {
        $user = $this->organizer();

        $this->actingAs($user)
            ->post('/host/events', $this->payload(['kind' => 'paid', 'price' => 3]))
            ->assertRedirect('/host/events');

        $this->assertSame(1, Event::count());
    }

    public function test_free_and_donation_tiers_are_exempt(): void
    {
        // PlatformFee::on() charges nothing on a zero base, so there is nothing
        // for a free ticket to fall below.
        $this->actingAs($this->organizer())
            ->post('/host/events', $this->payload(['kind' => 'free', 'price' => 0]))
            ->assertRedirect('/host/events');

        $this->actingAs($this->organizer())
            ->post('/host/events', $this->payload(['kind' => 'donation', 'price' => 0]))
            ->assertRedirect('/host/events');

        $this->assertSame(2, Event::count());
    }

    public function test_a_custom_organizer_rate_moves_that_organizers_floor(): void
    {
        $user = $this->organizer();
        $user->setFeeOverride(5, 1); // RM1 flat instead of the global RM3

        $this->assertSame(1.0, PlatformFee::minimumTicketPrice($user->fresh()));

        // RM2 is below the global RM3 floor but fine on their own RM1 rate.
        $this->actingAs($user->fresh())
            ->post('/host/events', $this->payload(['kind' => 'paid', 'price' => 2]))
            ->assertRedirect('/host/events');

        $this->assertSame(1, Event::count());
    }

    public function test_the_event_builder_is_told_the_floor(): void
    {
        $this->actingAs($this->organizer())
            ->get('/host/events/create')
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('platformFee.min_ticket_price', 3)
                ->where('platformFee.flat', 3));
    }
}

<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventPhoto;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a logged-out visitor may see on an organizer's public page.
 *
 * Photos were behind a login, which closed the most persuasive tab on the page —
 * they are the reason someone decides an organizer is worth going to. Members
 * were hidden outright; they are now previewed, which is an invitation rather
 * than a wall. A member row carries nothing but a name, so neither leaks
 * anything private.
 */
class OrganizerVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function organizerWith(int $photos = 0, int $buyers = 0): User
    {
        $host = User::factory()->create(['slug' => 'boardlah', 'name' => 'BoardLah']);

        $event = Event::create([
            'user_id' => $host->id, 'title' => 'Clocktower', 'slug' => 'clocktower',
            'status' => 'published', 'visibility' => 'public',
            'timezone' => 'Asia/Kuala_Lumpur', 'starts_at' => now()->addDay(),
        ]);

        for ($i = 0; $i < $photos; $i++) {
            EventPhoto::create(['event_id' => $event->id, 'path' => "https://img.test/p{$i}.jpg"]);
        }

        for ($i = 0; $i < $buyers; $i++) {
            Order::create([
                'reference' => 'R'.$i.uniqid(), 'event_id' => $event->id, 'status' => 'paid',
                'buyer_name' => "Buyer {$i}", 'buyer_email' => "b{$i}@example.test",
                'subtotal' => 10, 'total' => 10, 'currency' => 'MYR', 'paid_at' => now(),
            ]);
        }

        return $host;
    }

    public function test_photos_are_visible_without_logging_in(): void
    {
        $this->organizerWith(photos: 3);

        $this->get('/en-my/o/boardlah/')
            ->assertOk()
            ->assertInertia(fn ($p) => $p->has('photos', 3));
    }

    public function test_a_guest_sees_a_preview_of_members_not_an_empty_tab(): void
    {
        $this->organizerWith(buyers: 10);

        $this->get('/en-my/o/boardlah/')
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                // Four shown…
                ->has('members.attendees', 4)
                // …and the rest counted, so the prompt can name a number.
                ->where('members.hidden', 6));
    }

    public function test_a_signed_in_visitor_sees_every_member(): void
    {
        $this->organizerWith(buyers: 10);

        $this->actingAs(User::factory()->create())
            ->get('/en-my/o/boardlah/')
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->has('members.attendees', 10)
                ->where('members.hidden', 0));
    }

    public function test_nothing_is_hidden_when_there_is_nothing_beyond_the_preview(): void
    {
        $this->organizerWith(buyers: 2);

        // Two buyers fit inside the preview, so there is no "and N more" to show.
        $this->get('/en-my/o/boardlah/')
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('members.hidden', 0));
    }
}

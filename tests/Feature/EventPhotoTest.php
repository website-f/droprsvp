<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventPhoto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EventPhotoTest extends TestCase
{
    use RefreshDatabase;

    private function ownedEvent(User $host): Event
    {
        return Event::create(['user_id' => $host->id, 'title' => 'Gig', 'slug' => 'gig-'.uniqid(), 'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur']);
    }

    public function test_organizer_can_add_and_remove_event_photos(): void
    {
        $host = $this->organizer();
        $event = $this->ownedEvent($host);

        $this->actingAs($host)->post("/host/events/{$event->slug}/photos", ['paths' => ['/storage/a.jpg', '/storage/b.jpg']])->assertRedirect();
        $this->assertSame(2, $event->photos()->count());

        $photo = $event->photos()->first();
        $this->actingAs($host)->delete("/host/events/{$event->slug}/photos/{$photo->id}")->assertRedirect();
        $this->assertSame(1, $event->photos()->count());
    }

    public function test_a_non_owner_cannot_add_photos(): void
    {
        $event = $this->ownedEvent($this->organizer());
        $this->actingAs($this->organizer())->post("/host/events/{$event->slug}/photos", ['paths' => ['/storage/x.jpg']])->assertForbidden();
    }

    public function test_photos_show_on_the_organizer_page_for_signed_in_viewers(): void
    {
        $host = $this->organizer();
        $event = $this->ownedEvent($host);
        EventPhoto::create(['event_id' => $event->id, 'path' => '/storage/live.jpg']);
        $host->ensureSlug();

        // Photos are behind the auth wall — a signed-in viewer sees them.
        $this->actingAs(User::factory()->create())->get("/o/{$host->slug}")->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('public/organizer')
                ->has('photos', 1)
                ->where('photos.0.path', '/storage/live.jpg')
                ->where('organizer.photos_count', 1));
    }

    public function test_photos_are_hidden_from_logged_out_visitors(): void
    {
        $host = $this->organizer();
        $event = $this->ownedEvent($host);
        EventPhoto::create(['event_id' => $event->id, 'path' => '/storage/live.jpg']);
        $host->ensureSlug();

        // Guests get no photo data (auth wall) but the count is still exposed.
        $this->get("/o/{$host->slug}")->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('public/organizer')
                ->has('photos', 0)
                ->where('organizer.photos_count', 1));
    }
}

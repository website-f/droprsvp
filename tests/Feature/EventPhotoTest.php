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

    public function test_photos_show_on_the_public_organizer_page(): void
    {
        $host = $this->organizer();
        $event = $this->ownedEvent($host);
        EventPhoto::create(['event_id' => $event->id, 'path' => '/storage/live.jpg']);
        $host->ensureSlug();

        $this->get("/o/{$host->slug}")->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('public/organizer')
                ->has('photos', 1)
                ->where('photos.0.path', '/storage/live.jpg'));
    }
}

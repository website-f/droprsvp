<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EventModerationTest extends TestCase
{
    use RefreshDatabase;

    private function superadmin(): User
    {
        Role::findOrCreate('superadmin', 'web');
        $u = User::factory()->create();
        $u->assignRole('superadmin');

        return $u;
    }

    private function event(array $overrides = []): Event
    {
        $host = User::factory()->create();

        return Event::create(array_merge([
            'user_id' => $host->id, 'title' => 'Some Event', 'slug' => 'some-event',
            'status' => 'published', 'published_at' => now(), 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
        ], $overrides));
    }

    public function test_superadmin_can_view_event_detail(): void
    {
        $event = $this->event();

        $this->actingAs($this->superadmin())->get(route('admin.events.show', $event->slug))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('admin/events/show')->where('event.title', 'Some Event'));
    }

    public function test_superadmin_can_cancel_an_event_with_a_reason(): void
    {
        $event = $this->event();

        $this->actingAs($this->superadmin())->post(route('admin.events.cancel', $event->slug), [
            'reason' => 'Breaches content policy.',
        ])->assertRedirect(route('admin.events.show', $event->slug));

        $event->refresh();
        $this->assertSame('cancelled', $event->status);
        $this->assertSame('Breaches content policy.', $event->cancelled_reason);

        // A cancelled event drops out of the public marketplace.
        $this->get('/en-my')->assertInertia(fn (Assert $p) => $p->has('events.data', 0));
    }

    public function test_superadmin_can_restore_a_cancelled_event(): void
    {
        $event = $this->event(['status' => 'cancelled', 'cancelled_reason' => 'x']);

        $this->actingAs($this->superadmin())->post(route('admin.events.restore', $event->slug))
            ->assertRedirect(route('admin.events.show', $event->slug));

        $event->refresh();
        $this->assertSame('draft', $event->status);
        $this->assertNull($event->cancelled_reason);
    }

    public function test_non_superadmin_cannot_moderate(): void
    {
        $event = $this->event();
        $this->actingAs(User::factory()->create())
            ->post(route('admin.events.cancel', $event->slug))->assertForbidden();
    }
}

<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EventAppealTest extends TestCase
{
    use RefreshDatabase;

    private function superadmin(): User
    {
        Role::findOrCreate('superadmin', 'web');
        $u = User::factory()->create();
        $u->assignRole('superadmin');

        return $u;
    }

    private function cancelledEvent(User $host): Event
    {
        return Event::create([
            'user_id' => $host->id, 'title' => 'Gig', 'slug' => 'gig-'.uniqid(),
            'status' => 'cancelled', 'cancelled_reason' => 'Policy', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
        ]);
    }

    public function test_organizer_can_appeal_a_cancellation_and_admins_are_notified(): void
    {
        $admin = $this->superadmin();
        $host = $this->organizer();
        $event = $this->cancelledEvent($host);

        $this->actingAs($host)->post(route('host.events.reappeal', $event), [
            'reason' => 'This event follows the rules.',
            'attachments' => ['/uploads/proof1.jpg', '/uploads/proof2.jpg'],
        ])->assertRedirect();

        $event->refresh();
        $this->assertSame('pending', $event->appeal_status);
        $this->assertSame('This event follows the rules.', $event->appeal_reason);
        $this->assertCount(2, $event->appeal_attachments);
        $this->assertDatabaseHas('app_notifications', ['user_id' => $admin->id, 'type' => 'event']);
    }

    public function test_appeal_requires_reason_and_attachments(): void
    {
        $host = $this->organizer();
        $event = $this->cancelledEvent($host);

        $this->actingAs($host)->post(route('host.events.reappeal', $event), ['reason' => '', 'attachments' => []])
            ->assertSessionHasErrors(['reason', 'attachments']);
    }

    public function test_admin_restore_approves_the_appeal_and_notifies_the_organizer(): void
    {
        $admin = $this->superadmin();
        $host = $this->organizer();
        $event = $this->cancelledEvent($host);
        $event->update(['appeal_status' => 'pending', 'appeal_reason' => 'x', 'appeal_attachments' => ['/uploads/a.jpg'], 'appealed_at' => now()]);

        $this->actingAs($admin)->post(route('admin.events.restore', $event))->assertRedirect();

        $event->refresh();
        $this->assertSame('draft', $event->status);
        $this->assertNull($event->appeal_status);
        $this->assertDatabaseHas('app_notifications', ['user_id' => $host->id, 'title' => 'Your event was restored']);
    }

    public function test_admin_can_dismiss_an_appeal(): void
    {
        $admin = $this->superadmin();
        $host = $this->organizer();
        $event = $this->cancelledEvent($host);
        $event->update(['appeal_status' => 'pending']);

        $this->actingAs($admin)->post(route('admin.events.dismiss-appeal', $event))->assertRedirect();

        $event->refresh();
        $this->assertSame('cancelled', $event->status);
        $this->assertSame('dismissed', $event->appeal_status);
        $this->assertDatabaseHas('app_notifications', ['user_id' => $host->id, 'title' => 'Appeal reviewed']);
    }

    public function test_a_cancelled_event_redirects_the_public_to_home(): void
    {
        $host = $this->organizer();
        $event = $this->cancelledEvent($host);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get(route('events.show', $event))->assertRedirect(route('home'));
        // The owner can still preview it.
        $this->actingAs($host)->get(route('events.show', $event))->assertOk();
    }

    public function test_policy_keyword_notifies_admins_on_event_create(): void
    {
        $admin = $this->superadmin();
        $host = $this->organizer();

        $this->actingAs($host)->post(route('host.events.store'), [
            'title' => 'Big gambling night', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
            'sessions' => [], 'gallery' => [], 'sections' => [], 'tables' => [], 'ticketTypes' => [],
        ])->assertRedirect();

        $this->assertDatabaseHas('app_notifications', ['user_id' => $admin->id, 'type' => 'policy']);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TeamAccessTest extends TestCase
{
    use RefreshDatabase;

    private function event(User $owner): Event
    {
        return Event::create([
            'user_id' => $owner->id, 'title' => 'Owner Gig', 'slug' => 'owner-gig-'.uniqid(),
            'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur', 'starts_at' => now()->addDays(4),
        ]);
    }

    /** A collaborator, added to the owner's team and granted the organizer role. */
    private function collaborator(User $owner): User
    {
        $member = User::factory()->create();
        $owner->teamMembers()->create(['member_id' => $member->id, 'role' => 'manager']);
        $member->assignRole('organizer');

        return $member;
    }

    public function test_owner_adds_a_collaborator_by_email(): void
    {
        $owner = $this->organizer();
        $member = User::factory()->create(['email' => 'help@example.test']);

        $this->actingAs($owner)->post('/host/team', ['email' => 'HELP@example.test'])
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseHas('team_members', ['owner_id' => $owner->id, 'member_id' => $member->id, 'role' => 'manager']);
        $this->assertTrue($member->fresh()->hasRole('organizer'));
    }

    public function test_adding_an_unknown_email_is_rejected(): void
    {
        $owner = $this->organizer();

        $this->actingAs($owner)->post('/host/team', ['email' => 'ghost@example.test'])->assertSessionHasErrors('email');
    }

    public function test_you_cannot_add_yourself(): void
    {
        $owner = $this->organizer(['email' => 'me@example.test']);

        $this->actingAs($owner)->post('/host/team', ['email' => 'me@example.test'])->assertSessionHasErrors('email');
    }

    public function test_a_collaborator_can_manage_the_owners_event(): void
    {
        $owner = $this->organizer();
        $event = $this->event($owner);
        $member = $this->collaborator($owner);

        $this->actingAs($member)->get("/host/events/{$event->slug}/edit")->assertOk();
        $this->assertTrue($event->fresh()->isManageableBy($member));
    }

    public function test_a_collaborator_cannot_delete_the_owners_event(): void
    {
        $owner = $this->organizer();
        $event = $this->event($owner);
        $member = $this->collaborator($owner);

        $this->actingAs($member)->delete("/host/events/{$event->slug}")->assertForbidden();
        $this->assertNotSoftDeleted($event);
    }

    public function test_a_stranger_cannot_manage_the_event(): void
    {
        $owner = $this->organizer();
        $event = $this->event($owner);

        $this->actingAs($this->organizer())->get("/host/events/{$event->slug}/edit")->assertForbidden();
    }

    public function test_shared_events_appear_in_the_collaborators_event_list(): void
    {
        $owner = $this->organizer();
        $this->event($owner);
        $member = $this->collaborator($owner);

        $this->actingAs($member)->get('/host/events')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('host/events/index')
                ->has('events', 1)
                ->where('events.0.mine', false)
                ->where('events.0.owner_name', $owner->name));
    }

    public function test_a_collaborator_can_approve_a_refund_on_the_owners_event(): void
    {
        $owner = $this->organizer();
        $event = $this->event($owner);
        TicketType::create(['event_id' => $event->id, 'name' => 'GA', 'price' => 50, 'currency' => 'MYR', 'quantity' => 10, 'sold' => 1, 'is_active' => true]);
        $buyer = User::factory()->create();
        $order = Order::create(['reference' => 'DRSVP-TEAMR', 'event_id' => $event->id, 'status' => 'paid', 'total' => 50, 'currency' => 'MYR', 'paid_at' => now(), 'user_id' => $buyer->id]);
        $req = RefundRequest::create(['order_id' => $order->id, 'user_id' => $buyer->id, 'amount' => 50, 'status' => 'pending']);
        $member = $this->collaborator($owner);

        $this->actingAs($member)->post("/host/refunds/{$req->id}/approve", ['amount' => 50])
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('approved', $req->fresh()->status);
    }

    public function test_removing_a_collaborator_revokes_access(): void
    {
        $owner = $this->organizer();
        $event = $this->event($owner);
        $member = $this->collaborator($owner);
        $membership = $owner->teamMembers()->where('member_id', $member->id)->first();

        $this->actingAs($owner)->delete("/host/team/{$membership->id}")->assertRedirect();

        $this->assertFalse($event->fresh()->isManageableBy($member));
    }

    public function test_a_collaborator_cannot_remove_someone_from_anothers_team(): void
    {
        $owner = $this->organizer();
        $member = $this->collaborator($owner);
        $membership = $owner->teamMembers()->where('member_id', $member->id)->first();

        // The collaborator (or anyone else) can't delete the owner's membership row.
        $this->actingAs($member)->delete("/host/team/{$membership->id}")->assertForbidden();
    }
}

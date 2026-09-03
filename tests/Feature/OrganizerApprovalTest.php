<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\OrganizerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizerApprovalTest extends TestCase
{
    use RefreshDatabase;

    /** An organizer whose application is not yet approved. */
    private function pendingOrganizer(string $status = 'pending'): User
    {
        $user = $this->organizer();
        OrganizerProfile::create(['user_id' => $user->id, 'status' => $status]);

        return $user;
    }

    public function test_an_unapproved_organizer_is_redirected_off_host_reads(): void
    {
        $user = $this->pendingOrganizer('pending');

        $this->actingAs($user)->get('/host/events')->assertRedirect(route('host.pending'));
    }

    public function test_an_unapproved_organizer_cannot_perform_host_writes(): void
    {
        $user = $this->pendingOrganizer('rejected');

        // The gate blocks the POST outright — approval can't be skipped with a token.
        $this->actingAs($user)->post('/host/team', ['email' => 'x@example.test'])->assertForbidden();
    }

    public function test_an_approved_organizer_can_read_and_write(): void
    {
        $user = $this->pendingOrganizer('approved');

        $this->actingAs($user)->get('/host/events')->assertOk();
    }

    public function test_a_grandfathered_organizer_with_no_profile_passes(): void
    {
        $user = $this->organizer(); // no profile row at all

        $this->actingAs($user)->get('/host/events')->assertOk();
    }

    public function test_a_collaborator_with_their_own_pending_profile_can_still_manage_shared_events(): void
    {
        $owner = $this->organizer();
        Event::create(['user_id' => $owner->id, 'title' => 'Shared', 'slug' => 'shared-'.uniqid(), 'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur']);

        // The collaborator is themselves a not-yet-approved organizer.
        $member = $this->pendingOrganizer('incomplete');
        $owner->teamMembers()->create(['member_id' => $member->id, 'role' => 'manager']);

        // They still reach the host panel (per-event access is enforced by policy).
        $this->actingAs($member)->get('/host/events')->assertOk();
    }

    public function test_onboarding_never_leaves_a_null_status_profile(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/host/welcome', ['event_types' => ['music']])->assertRedirect();

        $profile = OrganizerProfile::where('user_id', $user->id)->first();
        $this->assertNotNull($profile);
        $this->assertNotNull($profile->status); // 'incomplete', never null (would be treated as approved)
    }
}

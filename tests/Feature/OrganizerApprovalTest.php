<?php

namespace Tests\Feature;

use App\Mail\OrganizerApplicationMail;
use App\Models\OrganizerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OrganizerApprovalTest extends TestCase
{
    use RefreshDatabase;

    private function applicant(string $status = 'incomplete'): User
    {
        $user = $this->organizer();
        $user->organizerProfile()->create(['status' => $status]);

        return $user;
    }

    private function superadmin(): User
    {
        Role::findOrCreate('superadmin', 'web');
        $user = User::factory()->create();
        $user->assignRole('superadmin');

        return $user;
    }

    public function test_a_new_organizer_is_gated_to_the_application(): void
    {
        $user = $this->applicant('incomplete');

        $this->actingAs($user)->get(route('host.events.index'))->assertRedirect(route('host.apply'));
    }

    public function test_an_organizer_with_no_profile_is_grandfathered_in(): void
    {
        // Existing organizers (no application row) keep working.
        $user = $this->organizer();

        $this->actingAs($user)->get(route('host.events.index'))->assertOk();
    }

    public function test_submitting_an_application_moves_it_to_pending(): void
    {
        $user = $this->applicant('incomplete');

        $this->actingAs($user)->post(route('host.apply.submit'), [
            'business_name' => 'Acme Events',
            'phone' => '+60 12-345 6789',
            'website' => 'https://acme.example',
            'bio' => 'We run tech meetups.',
            'gallery' => ['https://cdn.example/a.jpg'],
        ])->assertRedirect(route('host.pending'));

        $this->assertDatabaseHas('organizer_profiles', [
            'user_id' => $user->id, 'status' => 'pending', 'business_name' => 'Acme Events',
        ]);
        $this->assertNotNull($user->organizerProfile->fresh()->submitted_at);

        // A pending applicant is bounced to the "under review" page.
        $this->actingAs($user)->get(route('host.events.index'))->assertRedirect(route('host.pending'));
    }

    public function test_superadmin_can_approve_an_application(): void
    {
        Mail::fake();
        $user = $this->applicant('pending');
        $profile = $user->organizerProfile;

        $this->actingAs($this->superadmin())
            ->post(route('admin.organizers.approve', $profile))
            ->assertRedirect();

        $this->assertSame('approved', $profile->fresh()->status);
        // The freshly approved organizer can now reach the host area.
        $this->actingAs($user->fresh())->get(route('host.events.index'))->assertOk();
        Mail::assertSent(OrganizerApplicationMail::class, fn ($m) => $m->approved && $m->hasTo($user->email));
    }

    public function test_superadmin_can_reject_with_a_reason_and_the_applicant_can_reappeal(): void
    {
        Mail::fake();
        $user = $this->applicant('pending');
        $profile = $user->organizerProfile;

        $this->actingAs($this->superadmin())
            ->post(route('admin.organizers.reject', $profile), ['reason' => 'Please add a real website.'])
            ->assertRedirect();

        $this->assertDatabaseHas('organizer_profiles', [
            'id' => $profile->id, 'status' => 'rejected', 'review_reason' => 'Please add a real website.',
        ]);
        Mail::assertSent(OrganizerApplicationMail::class, fn ($m) => ! $m->approved && $m->hasTo($user->email));

        // A rejected organizer is sent back to the application to re-appeal…
        $this->actingAs($user->fresh())->get(route('host.events.index'))->assertRedirect(route('host.apply'));

        // …and re-submitting puts them back to pending.
        $this->actingAs($user->fresh())->post(route('host.apply.submit'), [
            'business_name' => 'Acme Events', 'phone' => '+60 12-345 6789', 'website' => 'https://acme.example',
        ])->assertRedirect(route('host.pending'));
        $this->assertSame('pending', $profile->fresh()->status);
    }

    public function test_rejecting_requires_a_reason(): void
    {
        $user = $this->applicant('pending');

        $this->actingAs($this->superadmin())
            ->post(route('admin.organizers.reject', $user->organizerProfile), [])
            ->assertSessionHasErrors('reason');
    }

    public function test_only_superadmins_can_review_applications(): void
    {
        $profile = $this->applicant('pending')->organizerProfile;

        $this->actingAs($this->organizer())
            ->get(route('admin.organizers.index'))
            ->assertForbidden();
        $this->actingAs($this->organizer())
            ->post(route('admin.organizers.approve', $profile))
            ->assertForbidden();
    }
}

<?php

namespace Tests\Feature;

use App\Models\OrganizerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class VendorApplicationEditLockTest extends TestCase
{
    use RefreshDatabase;

    private function superadmin(): User
    {
        Role::findOrCreate('superadmin', 'web');
        $u = User::factory()->create();
        $u->assignRole('superadmin');

        return $u;
    }

    private function pendingApplicant(): array
    {
        $user = $this->organizer();
        $profile = OrganizerProfile::create([
            'user_id' => $user->id, 'status' => 'pending', 'business_name' => 'Acme Events',
            'phone' => '0123456789', 'submitted_at' => now(),
        ]);

        return [$user, $profile];
    }

    public function test_applicant_can_edit_until_admin_opens_it(): void
    {
        [$user] = $this->pendingApplicant();

        // Not opened yet → the edit form is served.
        $this->actingAs($user)->get('/host/apply')->assertInertia(fn (Assert $p) => $p->component('host/apply'));

        // The pending screen advertises that editing is still available.
        $this->actingAs($user)->get('/host/pending')->assertInertia(fn (Assert $p) => $p
            ->component('host/pending')->where('editable', true));
    }

    public function test_opening_the_application_locks_editing(): void
    {
        [$user, $profile] = $this->pendingApplicant();

        // Superadmin opens the detail view → review_opened_at is stamped.
        $this->actingAs($this->superadmin())->get(route('admin.organizers.show', $profile))->assertOk();
        $this->assertNotNull($profile->fresh()->review_opened_at);

        // Applicant is now bounced to the read-only pending screen.
        $this->actingAs($user)->get('/host/apply')->assertRedirect(route('host.pending'));
        $this->actingAs($user)->get('/host/pending')->assertInertia(fn (Assert $p) => $p
            ->component('host/pending')->where('editable', false));
    }

    public function test_submitting_edits_is_blocked_once_locked(): void
    {
        [$user, $profile] = $this->pendingApplicant();
        $profile->update(['review_opened_at' => now()]);

        $this->actingAs($user)->post('/host/apply', [
            'business_name' => 'Renamed Co', 'phone' => '0100000000',
        ])->assertRedirect(route('host.pending'));

        // The application was NOT changed.
        $this->assertSame('Acme Events', $profile->fresh()->business_name);
    }

    public function test_resubmitting_a_rejected_application_unlocks_it_again(): void
    {
        $user = $this->organizer();
        OrganizerProfile::create([
            'user_id' => $user->id, 'status' => 'rejected', 'business_name' => 'Old',
            'phone' => '0123456789', 'submitted_at' => now()->subDay(), 'reviewed_at' => now()->subDay(),
            'review_opened_at' => now()->subDay(),
        ]);

        $this->actingAs($user)->post('/host/apply', [
            'business_name' => 'Fresh Co', 'phone' => '0198887777',
        ])->assertRedirect(route('host.pending'));

        $profile = $user->organizerProfile()->first();
        $this->assertSame('pending', $profile->status);
        $this->assertNull($profile->review_opened_at);  // new review cycle → editable again
    }
}

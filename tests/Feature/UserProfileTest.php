<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserProfileTest extends TestCase
{
    use RefreshDatabase;

    private function superadmin(): User
    {
        Role::findOrCreate('superadmin', 'web');
        $u = User::factory()->create();
        $u->assignRole('superadmin');

        return $u;
    }

    public function test_a_consumer_without_a_profile_is_sent_to_complete_it(): void
    {
        $u = User::factory()->needsProfile()->create();
        $this->actingAs($u)->get('/dashboard')->assertRedirect(route('profile.about-you'));
    }

    public function test_completing_the_profile_stores_details_and_lets_them_through(): void
    {
        $u = User::factory()->needsProfile()->create();

        $this->actingAs($u)->post('/profile/about-you', [
            'phone' => '0123456789', 'gender' => 'female', 'age_band' => '25-34', 'city' => 'Kuala Lumpur', 'country' => 'Malaysia',
        ])->assertRedirect();

        $u->refresh();
        $this->assertNotNull($u->profile_completed_at);
        $this->assertSame('female', $u->gender);
        $this->assertSame('Malaysia', $u->country);

        $this->actingAs($u)->get('/dashboard')->assertOk();
    }

    public function test_organizers_are_not_gated_by_the_profile_requirement(): void
    {
        Role::findOrCreate('organizer', 'web');
        $u = User::factory()->needsProfile()->create();
        $u->assignRole('organizer');

        $this->actingAs($u)->get('/dashboard')->assertOk();
    }

    public function test_admin_can_filter_users_by_role_and_export_csv(): void
    {
        $admin = $this->superadmin();
        Role::findOrCreate('organizer', 'web');
        $org = User::factory()->create(['name' => 'Org Person', 'country' => 'Malaysia']);
        $org->assignRole('organizer');
        User::factory()->create(['name' => 'Normal Person', 'country' => 'Singapore']);

        $this->actingAs($admin)->get('/admin/users?role=organizer')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('admin/users/index')->has('users.data', 1)
                ->where('users.data.0.name', 'Org Person'));

        $res = $this->actingAs($admin)->get('/admin/users/export');
        $res->assertOk();
        $this->assertStringContainsString('text/csv', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('Email', $res->streamedContent());
        $this->assertStringContainsString('Org Person', $res->streamedContent());
    }
}

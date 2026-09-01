<?php

namespace Tests\Feature;

use App\Mail\AccountStatusMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserModerationTest extends TestCase
{
    use RefreshDatabase;

    private function superadmin(): User
    {
        Role::findOrCreate('superadmin', 'web');
        $u = User::factory()->create();
        $u->assignRole('superadmin');

        return $u;
    }

    public function test_disabling_a_user_emails_them_and_blocks_access(): void
    {
        Mail::fake();
        $admin = $this->superadmin();
        $u = User::factory()->create(['password' => Hash::make('password')]);

        $this->actingAs($admin)->post("/admin/users/{$u->id}/disabled")->assertRedirect();
        $this->assertNotNull($u->fresh()->disabled_at);
        Mail::assertSent(AccountStatusMail::class, fn ($m) => $m->hasTo($u->email) && $m->disabled === true);

        // A disabled user is bounced back to /login on their next request.
        $this->actingAs($u->fresh())->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_reactivating_a_user_emails_them_and_restores_access(): void
    {
        Mail::fake();
        $admin = $this->superadmin();
        $u = User::factory()->create(['disabled_at' => now()]);

        $this->actingAs($admin)->post("/admin/users/{$u->id}/disabled")->assertRedirect();
        $this->assertNull($u->fresh()->disabled_at);
        Mail::assertSent(AccountStatusMail::class, fn ($m) => $m->hasTo($u->email) && $m->disabled === false);

        $this->actingAs($u->fresh())->get('/dashboard')->assertOk();
    }

    public function test_cannot_disable_self_or_a_superadmin(): void
    {
        $admin = $this->superadmin();
        $other = $this->superadmin();

        $this->actingAs($admin)->post("/admin/users/{$admin->id}/disabled")->assertRedirect();
        $this->assertNull($admin->fresh()->disabled_at);

        $this->actingAs($admin)->post("/admin/users/{$other->id}/disabled")->assertRedirect();
        $this->assertNull($other->fresh()->disabled_at);
    }
}

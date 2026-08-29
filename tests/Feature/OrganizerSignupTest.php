<?php

namespace Tests\Feature;

use App\Mail\RegistrationCodeMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OrganizerSignupTest extends TestCase
{
    use RefreshDatabase;

    public function test_sending_a_code_stores_it_and_emails_the_address(): void
    {
        Mail::fake();

        $this->post('/get-started/code', ['email' => 'new@example.com'])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('registration_codes', ['email' => 'new@example.com']);
        Mail::assertSent(RegistrationCodeMail::class, fn ($m) => $m->hasTo('new@example.com'));
    }

    public function test_existing_account_cannot_use_signup(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->post('/get-started/code', ['email' => 'taken@example.com'])
            ->assertSessionHasErrors('email');
    }

    public function test_full_flow_creates_a_verified_organizer_and_logs_in(): void
    {
        Mail::fake();

        $this->post('/get-started/code', ['email' => 'ann@example.com'])->assertSessionHasNoErrors();

        $code = null;
        Mail::assertSent(RegistrationCodeMail::class, function ($m) use (&$code) { $code = $m->code; return true; });

        $this->post('/get-started/verify', ['email' => 'ann@example.com', 'code' => $code])->assertSessionHasNoErrors();

        $this->post('/get-started/complete', [
            'first_name' => 'Ann', 'last_name' => 'Lee',
            'password' => 'secret-pass-123', 'password_confirmation' => 'secret-pass-123',
            'consent' => true,
        ])->assertRedirect(route('host.apply'));

        $this->assertAuthenticated();
        $user = User::where('email', 'ann@example.com')->firstOrFail();
        $this->assertSame('Ann Lee', $user->name);
        $this->assertTrue($user->hasRole('organizer'));
        $this->assertNotNull($user->email_verified_at);
        $this->assertDatabaseMissing('registration_codes', ['email' => 'ann@example.com']);
    }

    public function test_wrong_code_is_rejected(): void
    {
        Mail::fake();
        $this->post('/get-started/code', ['email' => 'bob@example.com']);

        $this->post('/get-started/verify', ['email' => 'bob@example.com', 'code' => '000000'])
            ->assertSessionHasErrors('code');
    }

    public function test_public_registration_creates_a_free_buyer(): void
    {
        $this->post(route('register.store'), [
            'name' => 'Free User', 'email' => 'free@example.com',
            'password' => 'password', 'password_confirmation' => 'password',
            'consent' => true,
        ])->assertRedirect(route('dashboard', absolute: false));

        $user = User::where('email', 'free@example.com')->firstOrFail();
        $this->assertTrue($user->hasRole('buyer'));
        $this->assertFalse($user->hasRole('organizer'));
    }

    public function test_free_attendee_is_blocked_from_the_host_area(): void
    {
        Role::findOrCreate('buyer', 'web');
        $buyer = User::factory()->create();
        $buyer->assignRole('buyer');

        $this->actingAs($buyer)->get(route('host.events.index'))->assertForbidden();
        $this->actingAs($buyer)->get(route('host.events.create'))->assertForbidden();
    }

    public function test_signed_in_user_can_upgrade_to_a_vendor(): void
    {
        Role::findOrCreate('buyer', 'web');
        $user = User::factory()->create();
        $user->assignRole('buyer');

        $this->actingAs($user)->post('/become-a-vendor')
            ->assertRedirect(route('host.apply'));

        $this->assertTrue($user->fresh()->hasRole('organizer'));
    }

    public function test_onboarding_saves_a_profile(): void
    {
        Role::findOrCreate('organizer', 'web');
        $user = User::factory()->create();
        $user->assignRole('organizer');

        $this->actingAs($user)->post('/host/welcome', [
            'event_types' => ['Music', 'Tech'],
            'events_per_year' => '2–5',
            'audience_size' => '50–200',
        ])->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('organizer_profiles', ['user_id' => $user->id, 'events_per_year' => '2–5']);
        $this->assertNotNull($user->organizerProfile->completed_at);
    }
}

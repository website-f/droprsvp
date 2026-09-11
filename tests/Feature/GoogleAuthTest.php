<?php

namespace Tests\Feature;

use App\Mail\WelcomeMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    private function configure(): void
    {
        Config::set('services.google.client_id', 'client-id');
        Config::set('services.google.client_secret', 'client-secret');
    }

    private function fakeGoogle(array $profile): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token'], 200),
            'www.googleapis.com/oauth2/v3/userinfo' => Http::response($profile, 200),
        ]);
    }

    public function test_button_is_hidden_and_routes_404_when_not_configured(): void
    {
        $this->get('/auth/google/redirect')->assertNotFound();
    }

    public function test_redirect_sends_the_user_to_google_with_state(): void
    {
        $this->configure();

        $res = $this->get('/auth/google/redirect');

        $res->assertRedirectContains('accounts.google.com/o/oauth2/v2/auth');
        $res->assertSessionHas('google_oauth_state');
    }

    public function test_callback_creates_a_free_buyer_and_logs_in(): void
    {
        $this->configure();
        $this->fakeGoogle(['sub' => 'g-123', 'email' => 'new@example.com', 'email_verified' => true, 'name' => 'New User', 'picture' => 'https://img/p.png']);

        $this->withSession(['google_oauth_state' => 'st'])
            ->get('/auth/google/callback?code=abc&state=st')
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();
        $user = User::where('email', 'new@example.com')->firstOrFail();
        $this->assertSame('g-123', $user->google_id);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue($user->hasRole('buyer'));
        $this->assertFalse($user->hasRole('organizer'));
    }

    public function test_callback_links_an_existing_account_by_email(): void
    {
        $this->configure();
        $existing = User::factory()->create(['email' => 'me@example.com', 'google_id' => null]);
        $this->fakeGoogle(['sub' => 'g-999', 'email' => 'me@example.com', 'email_verified' => true, 'name' => 'Me']);

        $this->withSession(['google_oauth_state' => 'st'])
            ->get('/auth/google/callback?code=abc&state=st')
            ->assertRedirect();

        $this->assertAuthenticatedAs($existing->fresh());
        $this->assertSame('g-999', $existing->fresh()->google_id);
        $this->assertSame(1, User::where('email', 'me@example.com')->count());
    }

    public function test_callback_rejects_a_mismatched_state(): void
    {
        $this->configure();

        $this->withSession(['google_oauth_state' => 'real'])
            ->get('/auth/google/callback?code=abc&state=forged')
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    /**
     * Without this, anyone able to create a Google account on an unverified
     * address could claim the matching DropRSVP account by email.
     */
    public function test_callback_refuses_an_unverified_google_email(): void
    {
        $this->configure();
        $victim = User::factory()->create(['email' => 'victim@example.com']);
        $this->fakeGoogle(['sub' => 'g-evil', 'email' => 'victim@example.com', 'email_verified' => false, 'name' => 'Not Me']);

        $this->withSession(['google_oauth_state' => 'st'])
            ->get('/auth/google/callback?code=abc&state=st')
            ->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertNull($victim->fresh()->google_id);
    }

    public function test_callback_refuses_a_disabled_account(): void
    {
        $this->configure();
        $user = User::factory()->create(['email' => 'off@example.com']);
        $user->disabled_at = now();
        $user->save();
        $this->fakeGoogle(['sub' => 'g-off', 'email' => 'off@example.com', 'email_verified' => true, 'name' => 'Off']);

        $this->withSession(['google_oauth_state' => 'st'])
            ->get('/auth/google/callback?code=abc&state=st')
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    /**
     * A guest-checkout account is created with a temp password and the
     * must_set_password flag. Signing in with Google proves the address, so the
     * flag is cleared — otherwise EnsurePasswordSet traps them straight after.
     */
    public function test_google_sign_in_clears_the_forced_password_reset_on_a_guest_account(): void
    {
        $this->configure();
        $guest = User::factory()->create(['email' => 'buyer@example.com']);
        $guest->must_set_password = true;
        $guest->save();
        $this->fakeGoogle(['sub' => 'g-buyer', 'email' => 'buyer@example.com', 'email_verified' => true, 'name' => 'Buyer']);

        $this->withSession(['google_oauth_state' => 'st'])
            ->get('/auth/google/callback?code=abc&state=st')
            ->assertRedirect();

        $this->assertAuthenticatedAs($guest->fresh());
        $this->assertFalse((bool) $guest->fresh()->must_set_password);
    }

    /** Same onboarding as a password sign-up: a new attendee completes "about you" first. */
    public function test_a_new_google_attendee_is_sent_through_the_same_onboarding_as_a_password_signup(): void
    {
        $this->configure();
        $this->fakeGoogle(['sub' => 'g-new2', 'email' => 'fresh@example.com', 'email_verified' => true, 'name' => 'Fresh']);

        $this->withSession(['google_oauth_state' => 'st'])
            ->get('/auth/google/callback?code=abc&state=st')
            ->assertRedirect(route('dashboard', absolute: false));

        $user = User::where('email', 'fresh@example.com')->firstOrFail();
        $this->assertNull($user->profile_completed_at);

        // Following the redirect lands on the same profile gate a password
        // sign-up hits, rather than straight into the dashboard.
        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('profile.about-you'));
    }

    public function test_the_welcome_email_is_sent_once_for_a_new_google_account(): void
    {
        $this->configure();
        Mail::fake();
        $this->fakeGoogle(['sub' => 'g-mail', 'email' => 'mailme@example.com', 'email_verified' => true, 'name' => 'Mail Me']);

        $this->withSession(['google_oauth_state' => 'st'])->get('/auth/google/callback?code=abc&state=st');

        Mail::assertSent(WelcomeMail::class, 1);
    }
}

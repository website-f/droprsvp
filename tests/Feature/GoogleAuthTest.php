<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
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
        $this->fakeGoogle(['sub' => 'g-123', 'email' => 'new@example.com', 'name' => 'New User', 'picture' => 'https://img/p.png']);

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
        $this->fakeGoogle(['sub' => 'g-999', 'email' => 'me@example.com', 'name' => 'Me']);

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
}

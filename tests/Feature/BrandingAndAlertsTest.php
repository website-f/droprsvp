<?php

namespace Tests\Feature;

use App\Mail\PlatformAlertMail;
use App\Models\AppNotification;
use App\Models\Setting;
use App\Models\User;
use App\Support\PlatformAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Two gaps reported together: an organizer had nowhere to upload a logo, and
 * nobody on the platform side was told when anyone signed up.
 */
class BrandingAndAlertsTest extends TestCase
{
    use RefreshDatabase;

    private function superadmin(): User
    {
        Role::findOrCreate('superadmin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');

        return $admin;
    }

    // ---- branding ----------------------------------------------------------

    public function test_anyone_can_set_a_profile_photo(): void
    {
        $user = User::factory()->create(['avatar' => null]);

        $this->actingAs($user)
            ->patch('/settings/branding', ['avatar' => 'https://img.test/me.jpg'])
            ->assertRedirect(route('profile.edit'));

        $this->assertSame('https://img.test/me.jpg', $user->fresh()->avatar);
    }

    public function test_an_organizer_can_set_their_company_logo_and_details(): void
    {
        $host = $this->organizer();

        $this->actingAs($host)->patch('/settings/branding', [
            'poster' => 'https://img.test/logo.png',
            'business_name' => 'BoardLah Entertainment',
            'website' => 'https://boardlah.test',
            'bio' => 'Board game sessions.',
        ])->assertRedirect(route('profile.edit'));

        $profile = $host->fresh()->organizerProfile;

        $this->assertSame('https://img.test/logo.png', $profile->poster);
        $this->assertSame('BoardLah Entertainment', $profile->business_name);
    }

    /**
     * The whole reason this moved off the application form: saving branding
     * there resets the profile to `pending` and reopens a review.
     */
    public function test_saving_branding_does_not_reopen_an_approval_review(): void
    {
        $host = $this->organizer();
        $host->organizerProfile()->updateOrCreate(['user_id' => $host->id], [
            'status' => 'approved', 'business_name' => 'Old Name', 'reviewed_at' => now(),
        ]);

        $this->actingAs($host)->patch('/settings/branding', [
            'poster' => 'https://img.test/new-logo.png',
            'business_name' => 'New Name',
        ])->assertRedirect(route('profile.edit'));

        $profile = $host->fresh()->organizerProfile;

        $this->assertSame('approved', $profile->status);
        $this->assertNotNull($profile->reviewed_at);
        $this->assertSame('New Name', $profile->business_name);
    }

    public function test_a_plain_attendee_cannot_set_organizer_fields(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch('/settings/branding', ['business_name' => 'Not mine'])
            ->assertSessionHasErrors('business_name');
    }

    public function test_the_settings_page_shows_the_current_branding(): void
    {
        $host = $this->organizer();
        $host->organizerProfile()->updateOrCreate(['user_id' => $host->id], ['business_name' => 'BoardLah', 'poster' => 'https://img.test/l.png']);

        $this->actingAs($host)->get('/settings/profile')->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('isOrganizer', true)
                ->where('branding.business_name', 'BoardLah')
                ->where('branding.poster', 'https://img.test/l.png'));
    }

    public function test_the_public_page_prefers_the_company_logo_over_a_personal_photo(): void
    {
        $host = $this->organizer();
        $host->forceFill(['avatar' => 'https://img.test/google-selfie.jpg', 'slug' => 'boardlah'])->save();
        $host->organizerProfile()->updateOrCreate(['user_id' => $host->id], ['poster' => 'https://img.test/logo.png']);

        // Signing up with Google used to pin a personal photo on a business page
        // forever, with the uploaded logo never shown.
        $this->get('/en-my/o/boardlah/')->assertOk()
            ->assertInertia(fn ($p) => $p->where('organizer.avatar', 'https://img.test/logo.png'));
    }

    // ---- alerts ------------------------------------------------------------

    public function test_a_new_attendee_signup_reaches_the_admins_and_the_inbox(): void
    {
        Mail::fake();
        $admin = $this->superadmin();
        Setting::put('support_email', 'contact@droprsvp.test');

        $this->post('/register', [
            'name' => 'Hazman',
            'email' => 'hazman@example.test',
            'password' => 'Password!234',
            'password_confirmation' => 'Password!234',
            'consent' => true,
        ]);

        $this->assertDatabaseHas('users', ['email' => 'hazman@example.test']);

        // The bell inbox…
        $this->assertTrue(
            AppNotification::where('user_id', $admin->id)->where('type', 'user')->exists(),
            'no in-app notification reached the superadmin',
        );
        // …and the support inbox.
        Mail::assertSent(PlatformAlertMail::class, fn ($m) => $m->hasTo('contact@droprsvp.test'));
    }

    public function test_an_organizer_application_alerts_the_admins(): void
    {
        Mail::fake();
        $admin = $this->superadmin();
        $host = $this->organizer();
        $host->organizerProfile()->updateOrCreate(['user_id' => $host->id], ['status' => 'incomplete']);

        $this->actingAs($host)->post('/host/apply', [
            'business_name' => 'BoardLah Entertainment',
            'email' => 'bookings@boardlah.test',
            'phone' => '0123456789',
        ]);

        $note = AppNotification::where('user_id', $admin->id)->where('type', 'organizer')->first();

        $this->assertNotNull($note, 'an application that blocks a user until reviewed must reach an admin');
        $this->assertStringContainsString('BoardLah', $note->body);
        // It blocks somebody until it is reviewed, so it is not just 'info'.
        $this->assertSame('warning', $note->level);

        Mail::assertSent(PlatformAlertMail::class);
    }

    public function test_an_alert_never_breaks_the_thing_that_triggered_it(): void
    {
        // No superadmins, no support address configured: raising must still be
        // a no-op rather than an exception on somebody's sign-up.
        Setting::put('support_email', '');
        config(['mail.from.address' => null]);

        PlatformAlert::raise(type: 'user', title: 'Nobody is listening');

        $this->assertTrue(true);
    }

    public function test_the_inbox_falls_back_to_the_from_address(): void
    {
        Setting::put('support_email', '');
        config(['mail.from.address' => 'no-reply@droprsvp.test']);

        $this->assertSame('no-reply@droprsvp.test', PlatformAlert::inbox());
    }
}

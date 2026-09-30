<?php

namespace Tests\Feature;

use App\Models\OrganizerProfile;
use App\Models\User;
use App\Support\Dates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Timestamps in the reader's timezone, and profile details that agree with
 * each other.
 *
 * Storage is UTC, which is right. But it was also what got FORMATTED, so an
 * organizer who applied at 11:11 in the morning read "3:11 AM" on their own
 * application, and anything between midnight and 8am Malaysian time showed the
 * previous day's date.
 */
class DisplayTimezoneAndProfileTest extends TestCase
{
    use RefreshDatabase;

    private function superadmin(): User
    {
        Role::findOrCreate('superadmin', 'web');
        $user = User::factory()->create();
        $user->assignRole('superadmin');

        return $user;
    }

    // ---- the timezone -------------------------------------------------------

    public function test_a_stored_utc_instant_reads_as_malaysian_time(): void
    {
        // 03:11 UTC is 11:11 in Kuala Lumpur — the exact figure that was
        // reported as wrong.
        $at = Carbon::parse('2026-09-30 03:11:00', 'UTC');

        $this->assertSame('30 Sep 2026, 11:11 AM', Dates::display($at, 'j M Y, g:i A'));
    }

    public function test_a_late_evening_instant_keeps_the_right_date(): void
    {
        // 30 Sep 18:00 UTC is already 1 Oct in Malaysia. Formatting the UTC
        // value showed the previous day for every evening action.
        $at = Carbon::parse('2026-09-30 18:00:00', 'UTC');

        $this->assertSame('1 Oct 2026', Dates::display($at, 'j M Y'));
    }

    public function test_display_is_null_safe(): void
    {
        // Most of these come off nullable columns, and every call site used to
        // be optional($x)->format(...).
        $this->assertNull(Dates::display(null));
    }

    public function test_the_display_timezone_is_configurable(): void
    {
        config(['app.display_timezone' => 'UTC']);

        $at = Carbon::parse('2026-09-30 03:11:00', 'UTC');

        $this->assertSame('30 Sep 2026, 3:11 AM', Dates::display($at, 'j M Y, g:i A'));
    }

    public function test_an_application_timestamp_renders_in_local_time(): void
    {
        $organizer = User::factory()->create();
        $profile = OrganizerProfile::create([
            'user_id' => $organizer->id,
            'business_name' => 'BoardLah Entertainment',
            'phone' => '01129278984',
            'status' => 'approved',
            'submitted_at' => Carbon::parse('2026-09-30 03:11:00', 'UTC'),
        ]);

        $this->actingAs($this->superadmin())
            ->get("/admin/organizers/{$profile->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('application.submitted_at', '30 Sep 2026, 11:11 AM'));
    }

    public function test_a_users_joined_date_renders_in_local_time(): void
    {
        $user = User::factory()->create();
        // 23:30 in Malaysia on 30 Sep, which is 15:30 UTC the same day.
        $user->forceFill(['created_at' => Carbon::parse('2026-09-30 15:30:00', 'UTC')])->save();

        $this->actingAs($this->superadmin())
            ->get("/admin/users/{$user->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('user.joined', '30 Sep 2026'));
    }

    // ---- the phone ----------------------------------------------------------

    public function test_a_phone_given_on_the_application_shows_on_the_user_page(): void
    {
        // The application wrote only to organizer_profiles.phone while this
        // page read users.phone, so an organizer who plainly entered a number
        // showed up as "Phone —".
        $organizer = User::factory()->create(['phone' => null]);
        OrganizerProfile::create([
            'user_id' => $organizer->id,
            'business_name' => 'BoardLah Entertainment',
            'phone' => '01129278984',
            'status' => 'approved',
        ]);

        $this->actingAs($this->superadmin())
            ->get("/admin/users/{$organizer->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('user.phone', '01129278984'));
    }

    public function test_the_accounts_own_phone_wins_over_the_application(): void
    {
        $organizer = User::factory()->create(['phone' => '0123334444']);
        OrganizerProfile::create([
            'user_id' => $organizer->id, 'phone' => '01129278984', 'status' => 'approved',
        ]);

        $this->actingAs($this->superadmin())
            ->get("/admin/users/{$organizer->id}")
            ->assertInertia(fn (Assert $p) => $p->where('user.phone', '0123334444'));
    }

    public function test_the_application_details_are_visible_on_the_user_page(): void
    {
        $organizer = User::factory()->create();
        OrganizerProfile::create([
            'user_id' => $organizer->id,
            'business_name' => 'BoardLah Entertainment',
            'website' => 'https://instagram.com/3dexpress',
            'phone' => '01129278984',
            'status' => 'approved',
        ]);

        $this->actingAs($this->superadmin())
            ->get("/admin/users/{$organizer->id}")
            ->assertInertia(fn (Assert $p) => $p
                ->where('organizerProfile.business_name', 'BoardLah Entertainment')
                ->where('organizerProfile.website', 'https://instagram.com/3dexpress'));
    }

    public function test_a_user_with_no_application_carries_no_application_block(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->superadmin())
            ->get("/admin/users/{$user->id}")
            ->assertInertia(fn (Assert $p) => $p->where('organizerProfile', null));
    }

    public function test_applying_records_the_phone_on_the_account_too(): void
    {
        // The apply routes are role-gated, so the role has to be ASSIGNED,
        // not merely created — otherwise the POST is redirected and never
        // reaches the controller at all.
        $user = $this->organizer(['phone' => null]);

        $this->actingAs($user)->post('/host/apply', [
            'business_name' => 'BoardLah Entertainment',
            'phone' => '01129278984',
            'website' => '',
            'bio' => 'We run board game nights.',
        ])->assertSessionHasNoErrors();

        // Sanity: the application itself was stored, so we know submit() ran.
        $this->assertDatabaseHas('organizer_profiles', ['user_id' => $user->id, 'phone' => '01129278984']);

        // One place to read it from, so the admin page and the search agree.
        $this->assertSame('01129278984', $user->fresh()->phone);
    }

    public function test_applying_does_not_overwrite_a_phone_they_already_set(): void
    {
        $user = $this->organizer(['phone' => '0123334444']);

        $this->actingAs($user)->post('/host/apply', [
            'business_name' => 'BoardLah Entertainment',
            'phone' => '01129278984',
            'bio' => 'We run board game nights.',
        ])->assertSessionHasNoErrors();

        // The account's own number was set deliberately and later.
        $this->assertSame('0123334444', $user->fresh()->phone);
    }
}

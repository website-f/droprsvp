<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventCategory;
use App\Models\OrganizerProfile;
use App\Models\User;
use App\Support\SeoTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Who an organizer is called in search results, and whether they have a public
 * URL at all.
 *
 * Both surfaced from `php artisan seo:check` on production: an event by a
 * company was going out under the account holder's personal name, and two of
 * four organizers had no slug, so their profile 404'd.
 */
class OrganizerNamingTest extends TestCase
{
    use RefreshDatabase;

    private function hostTradingAs(string $account, ?string $business): User
    {
        $host = $this->organizer(['name' => $account]);

        OrganizerProfile::create([
            'user_id' => $host->id,
            'business_name' => $business,
            'status' => 'approved',
        ]);

        return $host;
    }

    private function event(User $host): Event
    {
        $category = EventCategory::firstOrCreate(['slug' => 'tech'], ['name' => 'Tech', 'sort_order' => 1]);

        return Event::create([
            'user_id' => $host->id, 'category_id' => $category->id,
            'title' => 'Grand Opening', 'slug' => 'grand-opening',
            'description' => 'Come along.', 'status' => 'published', 'visibility' => 'public',
            'timezone' => 'Asia/Kuala_Lumpur', 'city' => 'Kuala Lumpur', 'venue_name' => 'Pavilion Bukit Jalil',
            'starts_at' => '2026-10-04 02:00:00', 'published_at' => now(),
        ]);
    }

    public function test_an_event_is_credited_to_the_business_not_the_account_holder(): void
    {
        $host = $this->hostTradingAs('Sophia Kuek', 'BoardLah Entertainment');
        $event = $this->event($host);

        $this->assertSame('BoardLah Entertainment', SeoTemplate::values($event)['{organizer}']);

        $this->get('/en-my/e/'.$event->slug)
            ->assertOk()
            ->assertSee('Tech event by BoardLah Entertainment at Pavilion Bukit Jalil', false)
            ->assertDontSee('event by Sophia Kuek', false);
    }

    public function test_the_event_and_the_profile_agree_on_the_name(): void
    {
        $host = $this->hostTradingAs('Sophia Kuek', 'BoardLah Entertainment');
        $event = $this->event($host);

        // One organizer, one name, wherever they appear.
        $this->assertSame(
            SeoTemplate::values($event)['{organizer}'],
            SeoTemplate::organizerValues($host)['{organizer}'],
        );
    }

    public function test_an_organizer_with_no_business_name_keeps_their_own(): void
    {
        $host = $this->hostTradingAs('Batrisyia Balqis', null);
        $event = $this->event($host);

        $this->assertSame('Batrisyia Balqis', SeoTemplate::values($event)['{organizer}']);
    }

    public function test_approving_an_application_gives_them_a_public_url(): void
    {
        $applicant = $this->organizer(['name' => 'My Hub Solution']);
        $applicant->forceFill(['slug' => null])->save();

        $profile = OrganizerProfile::create([
            'user_id' => $applicant->id,
            'business_name' => 'My Hub Solution',
            'status' => 'pending',
            'submitted_at' => now(),
        ]);

        $admin = User::factory()->create();
        Role::findOrCreate('superadmin', 'web');
        $admin->assignRole('superadmin');

        $this->assertNull($applicant->fresh()->slug, 'Precondition: no handle yet.');

        $this->actingAs($admin)->post(route('admin.organizers.approve', $profile));

        $slug = $applicant->fresh()->slug;
        $this->assertSame('my-hub-solution', $slug);

        // And the profile is actually reachable, which it was not before.
        $this->get('/en-my/o/'.$slug)->assertOk();
    }

    public function test_two_organizers_with_the_same_name_get_distinct_urls(): void
    {
        $first = $this->organizer(['name' => 'Acme Events']);
        $first->forceFill(['slug' => null])->save();
        $second = $this->organizer(['name' => 'Acme Events']);
        $second->forceFill(['slug' => null])->save();

        $this->assertSame('acme-events', $first->ensureSlug());
        $this->assertSame('acme-events-2', $second->ensureSlug());
    }
}

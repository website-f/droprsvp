<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use App\Support\Cities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Cities are grouped by state for the picker, but their SLUGS are load-bearing:
 * they are in the sitemap, they are indexed, and /en-my/kuala-lumpur/ has to
 * keep resolving. Regrouping the list must not move a single one.
 */
class CitiesByStateTest extends TestCase
{
    use RefreshDatabase;

    /** Every city that was live before states were introduced. */
    private const PREVIOUSLY_LIVE = [
        'Kuala Lumpur', 'Petaling Jaya', 'Shah Alam', 'Subang Jaya', 'Klang',
        'Putrajaya', 'Cyberjaya', 'George Town', 'Butterworth', 'Johor Bahru',
        'Ipoh', 'Melaka', 'Seremban', 'Kuantan', 'Kota Kinabalu', 'Kuching',
        'Alor Setar', 'Kota Bharu', 'Kuala Terengganu', 'Langkawi',
    ];

    public function test_no_previously_indexed_city_slug_changed(): void
    {
        foreach (self::PREVIOUSLY_LIVE as $name) {
            $slug = Str::slug($name);

            $this->assertSame($name, Cities::nameForSlug($slug), "the slug {$slug} no longer resolves");
            $this->assertTrue(Cities::isKnownSlug($slug));
        }
    }

    public function test_previously_indexed_city_pages_still_load(): void
    {
        // Spot-check the discovery pages Search Console already knows about.
        foreach (['kuala-lumpur', 'george-town', 'johor-bahru'] as $slug) {
            $this->get("/en-my/{$slug}/")->assertOk();
        }
    }

    public function test_every_city_belongs_to_exactly_one_state(): void
    {
        $seen = [];

        foreach (Cities::BY_STATE as $state => $cities) {
            foreach ($cities as $city) {
                // The message is built eagerly, so read the previous state defensively.
                $this->assertArrayNotHasKey($city, $seen, "{$city} is listed under both ".($seen[$city] ?? '?')." and {$state}");
                $seen[$city] = $state;
            }
        }

        $this->assertSame(count($seen), count(Cities::names()));
    }

    public function test_every_slug_is_unique(): void
    {
        // Two cities sharing a slug would make one of them unreachable.
        $slugs = array_map(fn (string $n) => Str::slug($n), Cities::names());

        $this->assertSame(count($slugs), count(array_unique($slugs)));
    }

    public function test_kajang_is_now_available_and_sits_in_selangor(): void
    {
        // The event that prompted this was in Kajang, which had nowhere to go.
        $this->assertSame('Selangor', Cities::stateForCity('Kajang'));
        $this->assertSame('Kajang', Cities::nameForSlug('kajang'));
    }

    public function test_grouped_gives_the_picker_states_with_their_cities(): void
    {
        $grouped = Cities::grouped();

        $selangor = collect($grouped)->firstWhere('state', 'Selangor');

        $this->assertNotNull($selangor);
        $this->assertContains('Klang', array_column($selangor['cities'], 'name'));
        $this->assertContains('Shah Alam', array_column($selangor['cities'], 'name'));
        // Kuala Lumpur is its own federal territory, not a Selangor city.
        $this->assertNotContains('Kuala Lumpur', array_column($selangor['cities'], 'name'));
    }

    public function test_the_flat_list_still_carries_name_and_slug_and_now_state(): void
    {
        $all = Cities::all();

        $this->assertArrayHasKey('name', $all[0]);
        $this->assertArrayHasKey('slug', $all[0]);
        $this->assertArrayHasKey('state', $all[0]);
    }

    public function test_an_event_in_a_newly_added_city_gets_a_working_landing_page(): void
    {
        $host = User::factory()->create();
        Event::create([
            'user_id' => $host->id, 'title' => 'Clocktower', 'slug' => 'clocktower',
            'status' => 'published', 'visibility' => 'public', 'city' => 'Kajang',
            'timezone' => 'Asia/Kuala_Lumpur', 'starts_at' => now()->addDay(),
        ]);

        $this->get('/en-my/kajang/')->assertOk()->assertSee('Clocktower', false);
    }

    public function test_the_event_builder_receives_cities_with_their_states(): void
    {
        $this->actingAs($this->organizer())
            ->get('/host/events/create')
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where(
                'cities.0.state',
                Cities::all()[0]['state'],
            ));
    }
}

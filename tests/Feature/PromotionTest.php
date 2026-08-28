<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Promotion;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PromotionTest extends TestCase
{
    use RefreshDatabase;

    private function event(User $host): Event
    {
        return Event::create([
            'user_id' => $host->id, 'title' => 'Boostable', 'slug' => 'boostable',
            'status' => 'published', 'published_at' => now(), 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
        ]);
    }

    public function test_organizer_sees_the_promote_page_with_price_and_fee(): void
    {
        $host = $this->organizer();
        Setting::put('boost_price', 49);
        Setting::put('platform_fee_percent', 5);
        $event = $this->event($host);

        $this->actingAs($host)->get(route('host.events.promote', $event))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('host/events/promote')
                ->where('boost.price', 49)
                ->where('platform_fee_percent', 5));
    }

    public function test_boosting_via_fake_gateway_settles_instantly_and_features_the_event(): void
    {
        $host = $this->organizer();
        Setting::put('boost_price', 49);
        Setting::put('boost_days', 7);
        $event = $this->event($host);

        // Dev uses the fake gateway → instant settle.
        $this->actingAs($host)->post(route('host.events.promote.store', $event))->assertRedirect();

        $this->assertDatabaseHas('promotions', ['event_id' => $event->id, 'status' => 'paid']);
        $event->refresh();
        $this->assertTrue($event->isBoosted());
        $this->assertTrue($event->boosted_until->between(now()->addDays(6), now()->addDays(8)));
    }

    public function test_a_non_owner_cannot_boost_an_event(): void
    {
        $event = $this->event($this->organizer());
        $this->actingAs($this->organizer())->post(route('host.events.promote.store', $event))->assertForbidden();
    }

    public function test_boosted_events_surface_first_in_discovery(): void
    {
        $host = $this->organizer();
        Event::create(['user_id' => $host->id, 'title' => 'Plain', 'slug' => 'plain', 'status' => 'published', 'published_at' => now(), 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur', 'starts_at' => now()->addDay()]);
        Event::create(['user_id' => $host->id, 'title' => 'Boosted', 'slug' => 'boosted-ev', 'status' => 'published', 'published_at' => now(), 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur', 'starts_at' => now()->addDays(5), 'boosted_until' => now()->addDays(3)]);

        $this->get('/en-my')->assertInertia(fn (Assert $p) => $p
            ->where('events.data.0.title', 'Boosted')
            ->where('events.data.0.boosted', true));
    }

    public function test_superadmin_can_update_monetization_settings(): void
    {
        Role::findOrCreate('superadmin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');

        $this->actingAs($admin)->post(route('admin.settings.save'), [
            'fee_percent' => 8, 'boost_price' => 99, 'boost_days' => 14, 'premium_price' => 25, 'premium_days' => 30, 'tax_percent' => 6, 'tax_label' => 'SST',
        ])->assertRedirect();

        $this->assertSame('99', Setting::get('boost_price'));
        $this->assertSame('14', Setting::get('boost_days'));
        $this->assertSame('25', Setting::get('premium_price'));
        $this->assertSame('6', Setting::get('tax_percent'));
    }
}

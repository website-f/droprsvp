<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchTest extends TestCase
{
    use RefreshDatabase;

    private function event(string $title, string $slug): Event
    {
        return Event::create([
            'user_id' => User::factory()->create()->id, 'title' => $title, 'slug' => $slug,
            'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur', 'starts_at' => now()->addWeek(),
        ]);
    }

    public function test_suggest_returns_matching_events_with_canonical_urls(): void
    {
        $this->event('Jazz Night', 'jazz-night');
        $this->event('Rock Fest', 'rock-fest');

        $this->getJson('/search/suggest?q=jazz')->assertOk()
            ->assertJsonPath('events.0.label', 'Jazz Night')
            ->assertJsonPath('events.0.url', '/en-my/e/jazz-night');
    }

    public function test_admin_trending_keywords_surface_as_hot(): void
    {
        Setting::put('trending_keywords', 'Concerts, Food Festivals');

        $hot = collect($this->getJson('/search/suggest?q=')->assertOk()->json('hot'))->pluck('label');

        $this->assertTrue($hot->contains('Concerts'));
        $this->assertTrue($hot->contains('Food Festivals'));
    }
}

<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DiscoverTest extends TestCase
{
    use RefreshDatabase;

    private function makeEvent(string $title, array $overrides = []): Event
    {
        $user = User::factory()->create();

        return Event::create(array_merge([
            'user_id' => $user->id, 'title' => $title, 'slug' => \Illuminate\Support\Str::slug($title),
            'status' => 'published', 'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur',
            'starts_at' => now()->addDays(7),
        ], $overrides));
    }

    public function test_browse_lists_only_published_public_events(): void
    {
        $this->makeEvent('Jazz Night');
        $this->makeEvent('Draft Gig', ['status' => 'draft', 'slug' => 'draft-gig']);
        $this->makeEvent('Private Party', ['visibility' => 'private', 'slug' => 'private-party']);
        $this->makeEvent('Past Show', ['starts_at' => now()->subDays(3), 'slug' => 'past-show']);

        $this->get('/events')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('public/events/index')
                ->has('events.data', 1)
                ->where('events.data.0.title', 'Jazz Night'));
    }

    public function test_search_filters_by_keyword(): void
    {
        $this->makeEvent('Jazz Night');
        $this->makeEvent('Rock Fest', ['slug' => 'rock-fest']);

        $this->get('/events?q=jazz')
            ->assertInertia(fn (Assert $p) => $p->has('events.data', 1)->where('events.data.0.title', 'Jazz Night'));
    }

    public function test_category_filter_works(): void
    {
        $music = EventCategory::create(['name' => 'Music', 'slug' => 'music']);
        $this->makeEvent('With Category', ['category_id' => $music->id, 'slug' => 'with-cat']);
        $this->makeEvent('No Category', ['slug' => 'no-cat']);

        $this->get('/events?category=music')
            ->assertInertia(fn (Assert $p) => $p->has('events.data', 1)->where('events.data.0.title', 'With Category'));
    }
}

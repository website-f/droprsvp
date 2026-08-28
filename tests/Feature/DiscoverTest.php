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

    public function test_locale_root_lists_only_published_public_events(): void
    {
        $this->makeEvent('Jazz Night');
        $this->makeEvent('Draft Gig', ['status' => 'draft', 'slug' => 'draft-gig']);
        $this->makeEvent('Private Party', ['visibility' => 'private', 'slug' => 'private-party']);
        $this->makeEvent('Past Show', ['starts_at' => now()->subDays(3), 'slug' => 'past-show']);

        $this->get('/en-my')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('public/events/index')
                ->has('events.data', 1)
                ->where('events.data.0.title', 'Jazz Night'));
    }

    public function test_legacy_events_url_redirects_to_locale_path(): void
    {
        $this->get('/events')->assertRedirect('/en-my');
        $this->get('/events?category=music')->assertRedirect('/en-my/all/music');
    }

    public function test_search_filters_by_keyword(): void
    {
        $this->makeEvent('Jazz Night');
        $this->makeEvent('Rock Fest', ['slug' => 'rock-fest']);

        $this->get('/en-my?q=jazz')
            ->assertInertia(fn (Assert $p) => $p->has('events.data', 1)->where('events.data.0.title', 'Jazz Night'));
    }

    public function test_category_path_filters(): void
    {
        $music = EventCategory::create(['name' => 'Music', 'slug' => 'music']);
        $this->makeEvent('With Category', ['category_id' => $music->id, 'slug' => 'with-cat']);
        $this->makeEvent('No Category', ['slug' => 'no-cat']);

        $this->get('/en-my/all/music')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->has('events.data', 1)->where('events.data.0.title', 'With Category')
                ->where('active.category', 'music'));
    }

    public function test_city_path_filters(): void
    {
        $this->makeEvent('KL Show', ['city' => 'Kuala Lumpur', 'slug' => 'kl-show']);
        $this->makeEvent('Penang Show', ['city' => 'George Town', 'slug' => 'penang-show']);

        $this->get('/en-my/kuala-lumpur')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->has('events.data', 1)
                ->where('events.data.0.title', 'KL Show')
                ->where('active.city_name', 'Kuala Lumpur'));
    }

    public function test_city_and_category_path_filters(): void
    {
        $music = EventCategory::create(['name' => 'Music', 'slug' => 'music']);
        $this->makeEvent('KL Music', ['city' => 'Kuala Lumpur', 'category_id' => $music->id, 'slug' => 'kl-music']);
        $this->makeEvent('KL Other', ['city' => 'Kuala Lumpur', 'slug' => 'kl-other']);
        $this->makeEvent('PG Music', ['city' => 'George Town', 'category_id' => $music->id, 'slug' => 'pg-music']);

        $this->get('/en-my/kuala-lumpur/music')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->has('events.data', 1)->where('events.data.0.title', 'KL Music'));
    }

    public function test_unknown_city_slug_is_404(): void
    {
        $this->get('/en-my/atlantis')->assertNotFound();
    }
}

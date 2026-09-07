<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventCategory;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResetDataCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_clears_dummy_data_keeps_content_and_makes_a_superadmin(): void
    {
        // Seed content that MUST survive + dummy data that MUST be wiped.
        $category = EventCategory::create(['name' => 'Music', 'slug' => 'music', 'sort_order' => 0]);
        Setting::put('robots_txt', 'User-agent: *');
        $host = User::factory()->create();
        Event::create([
            'user_id' => $host->id, 'title' => 'Dummy', 'slug' => 'dummy', 'status' => 'published',
            'visibility' => 'public', 'timezone' => 'Asia/Kuala_Lumpur', 'category_id' => $category->id,
        ]);

        $this->artisan('droprsvp:reset-data', ['--force' => true, '--email' => 'contact@droprsvp.com', '--password' => 'droprsvp2026!'])
            ->assertExitCode(0);

        // Dummy data gone.
        $this->assertSame(0, Event::count());

        // Content preserved.
        $this->assertDatabaseHas('event_categories', ['slug' => 'music']);
        $this->assertSame('User-agent: *', Setting::get('robots_txt'));

        // Exactly one user — the fresh superadmin.
        $this->assertSame(1, User::count());
        $admin = User::first();
        $this->assertSame('contact@droprsvp.com', $admin->email);
        $this->assertTrue($admin->hasRole('superadmin'));
    }
}

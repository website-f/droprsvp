<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Roles + first superadmin (the platform owner).
        $this->call(RolesSeeder::class);

        // Starter event categories for the marketplace + event builder.
        foreach (['Music', 'Business', 'Food & Drink', 'Tech', 'Community', 'Sports', 'Arts', 'Wellness'] as $i => $name) {
            \App\Models\EventCategory::firstOrCreate(
                ['slug' => \Illuminate\Support\Str::slug($name)],
                ['name' => $name, 'sort_order' => $i],
            );
        }

        // Showcase events with real cover photos (so the marketplace isn't empty).
        $this->call(SampleEventsSeeder::class);
    }
}

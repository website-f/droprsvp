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

        // Starter event categories for the marketplace + event builder — each with
        // its homepage look (icon / subtitle / colour), all editable in admin.
        $categories = [
            ['Music', 'music', 'Gigs & live sets', '#6c63ff'],
            ['Business', 'briefcase', 'Talks & networking', '#2ec4b6'],
            ['Food & Drink', 'utensils', 'Tastings & festivals', '#f5a524'],
            ['Tech', 'cpu', 'Meetups & demos', '#3b82f6'],
            ['Community', 'users', 'Local get-togethers', '#ff6584'],
            ['Sports', 'dumbbell', 'Games & fitness', '#f97316'],
            ['Arts', 'palette', 'Shows & workshops', '#a855f7'],
            ['Wellness', 'heart-pulse', 'Yoga & retreats', '#22c55e'],
        ];
        foreach ($categories as $i => [$name, $icon, $blurb, $color]) {
            \App\Models\EventCategory::firstOrCreate(
                ['slug' => \Illuminate\Support\Str::slug($name)],
                ['name' => $name, 'sort_order' => $i, 'icon' => $icon, 'blurb' => $blurb, 'color' => $color],
            );
        }

        // Showcase events with real cover photos (so the marketplace isn't empty).
        $this->call(SampleEventsSeeder::class);

        // Default help-center content.
        $this->call(HelpArticlesSeeder::class);

        // Privacy Policy + Terms pages (editable under Admin → Legal pages).
        $this->call(LegalPagesSeeder::class);
    }
}

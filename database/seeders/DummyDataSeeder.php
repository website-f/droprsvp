<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\EventComment;
use App\Models\EventDailyStat;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Showcase data: decorates every published event with participants (paid orders
 * + tickets), reviews & ratings, a bit of discussion, 30 days of reach stats,
 * and gives each organizer followers — so a fresh demo looks alive.
 *
 *   php artisan db:seed --class=DummyDataSeeder
 *
 * Additive and safe to re-run (dummy users are reused; reviews are upserted).
 */
class DummyDataSeeder extends Seeder
{
    public function run(): void
    {
        if (! Event::published()->exists()) {
            $this->call(SampleEventsSeeder::class);
        }

        $events = Event::published()->with('ticketTypes')->get();
        if ($events->isEmpty()) {
            $this->command?->warn('No published events — nothing to seed.');

            return;
        }

        // A reusable pool of fake attendees / followers.
        $users = collect(range(1, 40))->map(fn ($i) => User::firstOrCreate(
            ['email' => "guest{$i}@showcase.test"],
            ['name' => fake()->name(), 'password' => bcrypt('password')],
        ));

        $cities = ['Kuala Lumpur', 'Petaling Jaya', 'George Town', 'Johor Bahru', 'Ipoh', 'Shah Alam', 'Melaka'];
        $genders = ['female', 'male', 'other', 'na'];
        $ages = ['under-18', '18-24', '25-34', '35-44', '45-54', '55+'];
        $sources = ['instagram', 'facebook', 'tiktok', 'friend', 'search', 'email', 'other'];
        $reviewBodies = [
            'Loved every minute — well organized and a great crowd.',
            'Solid event, would definitely come again.',
            'Great vibe and a smooth check-in at the door.',
            'Good value for the ticket. Venue was a bit packed.',
            'Fantastic line-up and friendly people.',
            'Well run — started on time with clear signage.',
            'Enjoyed it overall, could use more food options.',
            'One of the best events I’ve been to this year.',
        ];
        $questions = ['Is there parking nearby?', 'Are tickets still available?', 'What time do doors open?', 'Is it kid-friendly?', 'Any student discount?'];
        $replies = ['Yes — plenty of parking next door.', 'Grab them soon, going fast!', 'Doors open at 7pm.', 'Absolutely, all ages welcome.', 'Drop us a message and we’ll sort you out.'];

        foreach ($events as $event) {
            $tt = $event->ticketTypes->firstWhere('kind', 'paid')
                ?? $event->ticketTypes->first()
                ?? $event->ticketTypes()->create(['name' => 'General', 'kind' => 'paid', 'price' => 50, 'quantity' => 500, 'min_per_order' => 1, 'max_per_order' => 10, 'is_active' => true]);
            $price = (float) ($tt->price ?: 50);

            // Participants — paid orders + a ticket each, with demographics.
            foreach ($users->shuffle()->take(rand(8, 24)) as $u) {
                $order = Order::create([
                    'reference' => 'DUMMY-'.strtoupper(Str::random(8)),
                    'event_id' => $event->id, 'user_id' => $u->id, 'status' => 'paid',
                    'buyer_name' => $u->name, 'buyer_email' => $u->email, 'buyer_phone' => fake()->numerify('01#-### ####'),
                    'buyer_gender' => fake()->randomElement($genders), 'buyer_age_band' => fake()->randomElement($ages),
                    'buyer_city' => fake()->randomElement($cities), 'buyer_source' => fake()->randomElement($sources),
                    'subtotal' => $price, 'total' => $price, 'currency' => 'MYR', 'paid_at' => now()->subDays(rand(0, 25)),
                ]);
                $order->items()->create(['ticket_type_id' => $tt->id, 'name' => $tt->name, 'unit_price' => $price, 'quantity' => 1, 'line_total' => $price]);
                $order->tickets()->create(['event_id' => $event->id, 'ticket_type_id' => $tt->id, 'attendee_name' => $u->name, 'status' => fake()->boolean(25) ? 'checked_in' : 'valid']);
                $tt->increment('sold');
            }

            // Reviews & ratings (one per user, weighted positive).
            foreach ($users->shuffle()->take(rand(3, 8)) as $u) {
                if ($u->id === $event->user_id) {
                    continue;
                }
                $event->reviews()->updateOrCreate(['user_id' => $u->id], [
                    'rating' => fake()->randomElement([5, 5, 5, 4, 4, 4, 3, 3, 2]),
                    'body' => fake()->boolean(80) ? fake()->randomElement($reviewBodies) : null,
                ]);
            }

            // Discussion — a few questions, most with an organizer reply.
            foreach ($users->shuffle()->take(rand(1, 3)) as $u) {
                if ($u->id === $event->user_id) {
                    continue;
                }
                $q = $event->comments()->create(['user_id' => $u->id, 'body' => fake()->randomElement($questions)]);
                if (fake()->boolean(70)) {
                    EventComment::create(['event_id' => $event->id, 'user_id' => $event->user_id, 'parent_id' => $q->id, 'body' => fake()->randomElement($replies)]);
                }
            }

            // 30 days of reach (impressions + clicks) for the analytics charts.
            for ($d = 0; $d < 30; $d++) {
                EventDailyStat::updateOrCreate(
                    ['event_id' => $event->id, 'stat_date' => today()->subDays($d)],
                    ['impressions' => rand(20, 120), 'clicks' => rand(2, 25)],
                );
            }
        }

        // Followers — each organizer gets a following.
        foreach (Event::published()->pluck('user_id')->unique() as $organizerId) {
            $followerIds = $users->where('id', '!=', $organizerId)->shuffle()->take(rand(6, 30))->pluck('id');
            User::find($organizerId)?->followers()->syncWithoutDetaching($followerIds);
        }

        $this->command?->info('Showcase data seeded: participants, reviews, discussion, reach stats + followers across '.$events->count().' event(s).');
    }
}

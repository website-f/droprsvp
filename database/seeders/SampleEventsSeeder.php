<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\EventCategory;
use App\Models\EventSession;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * Three showcase events with real cover photos (Unsplash) so the marketplace and
 * landing page look alive out of the box. Idempotent — keyed on event slug.
 */
class SampleEventsSeeder extends Seeder
{
    public function run(): void
    {
        Role::firstOrCreate(['name' => 'organizer', 'guard_name' => 'web']);

        $host = User::firstOrCreate(
            ['email' => 'host@droprsvp.test'],
            ['name' => 'DropRSVP Events', 'password' => Hash::make('password')],
        );
        if (! $host->hasRole('organizer')) {
            $host->assignRole('organizer');
        }

        $cat = fn (string $slug) => optional(EventCategory::where('slug', $slug)->first())->id;

        $events = [
            [
                'slug' => 'neon-nights-rooftop-live',
                'title' => 'Neon Nights: Rooftop Live',
                'subtitle' => 'An open-air night of live indie & electronic sets',
                'category_id' => $cat('music'),
                'cover' => 'https://images.unsplash.com/photo-1459749411175-04bf5292ceea?q=80&w=1200&auto=format&fit=crop',
                'venue' => 'The Deck, Bangsar',
                'address' => 'Jalan Telawi 3, Bangsar, Kuala Lumpur',
                'days' => 12, 'hour' => 20,
                'description' => 'Doors at 8 PM. Four acts, one skyline. Grab a drink and catch the best of KL’s indie and electronic scene under the stars.',
                'tickets' => [
                    ['name' => 'Early Bird', 'kind' => 'paid', 'price' => 45, 'quantity' => 100],
                    ['name' => 'General Admission', 'kind' => 'paid', 'price' => 65, 'quantity' => 250],
                ],
            ],
            [
                'slug' => 'founders-breakfast-kl',
                'title' => 'Founders Breakfast KL',
                'subtitle' => 'Coffee, croissants and candid startup talk',
                'category_id' => $cat('business'),
                'cover' => 'https://images.unsplash.com/photo-1540575467063-178a50c2df87?q=80&w=1200&auto=format&fit=crop',
                'venue' => 'WORQ, TTDI',
                'address' => 'Jalan Wan Kadir, Taman Tun Dr Ismail, Kuala Lumpur',
                'days' => 6, 'hour' => 8,
                'description' => 'A relaxed monthly meetup for founders and operators. Short fireside chat, then open networking over breakfast. Come with one question worth asking.',
                'tickets' => [
                    ['name' => 'Community (Free)', 'kind' => 'free', 'price' => 0, 'quantity' => 60],
                    ['name' => 'Supporter', 'kind' => 'paid', 'price' => 30, 'quantity' => 40],
                ],
            ],
            [
                'slug' => 'taste-of-bangsar-street-food-fest',
                'title' => 'Taste of Bangsar: Street Food Fest',
                'subtitle' => 'A weekend of hawker legends & new-wave kitchens',
                'category_id' => $cat('food-drink'),
                'cover' => 'https://images.unsplash.com/photo-1504674900247-0877df9cc836?q=80&w=1200&auto=format&fit=crop',
                'venue' => 'Jalan Telawi, Bangsar',
                'address' => 'Telawi, Bangsar Baru, Kuala Lumpur',
                'days' => 20, 'hour' => 17,
                'description' => 'Thirty stalls, live cooking and long communal tables. Bring an appetite — entry includes your first tasting token.',
                'tickets' => [
                    ['name' => 'Entry + Token', 'kind' => 'paid', 'price' => 15, 'quantity' => 500],
                    ['name' => 'Family Pass (4)', 'kind' => 'paid', 'price' => 40, 'quantity' => 150],
                ],
            ],
        ];

        foreach ($events as $data) {
            $starts = now()->addDays($data['days'])->setTime($data['hour'], 0);

            $event = Event::updateOrCreate(
                ['slug' => $data['slug']],
                [
                    'user_id' => $host->id,
                    'category_id' => $data['category_id'],
                    'title' => $data['title'],
                    'subtitle' => $data['subtitle'],
                    'description' => $data['description'],
                    'cover_image' => $data['cover'],
                    'status' => 'published',
                    'visibility' => 'public',
                    'timezone' => 'Asia/Kuala_Lumpur',
                    'is_online' => false,
                    'venue_name' => $data['venue'],
                    'venue_address' => $data['address'],
                    'starts_at' => $starts,
                    'ends_at' => $starts->copy()->addHours(4),
                    'published_at' => now(),
                ],
            );

            EventSession::updateOrCreate(
                ['event_id' => $event->id, 'sort_order' => 0],
                ['title' => 'Main', 'starts_at' => $starts, 'ends_at' => $starts->copy()->addHours(4)],
            );

            foreach ($data['tickets'] as $i => $t) {
                TicketType::updateOrCreate(
                    ['event_id' => $event->id, 'name' => $t['name']],
                    [
                        'kind' => $t['kind'],
                        'price' => $t['price'],
                        'currency' => 'MYR',
                        'quantity' => $t['quantity'],
                        'is_active' => true,
                        'sort_order' => $i,
                    ],
                );
            }
        }
    }
}

<?php

use App\Models\EventCategory;
use Illuminate\Database\Migrations\Migration;

/**
 * Starter SEO copy for each event category.
 *
 * Every category page used to render the SAME generic block, which is duplicate
 * content across eight URLs and tells a reader nothing about the category they
 * actually chose. Each now carries its own, editable in Admin → Categories.
 *
 * Only writes where `content` is EMPTY, so it is safe to re-run and can never
 * overwrite something an admin has since written.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->copy() as $slug => $html) {
            EventCategory::where('slug', $slug)
                ->where(fn ($q) => $q->whereNull('content')->orWhere('content', ''))
                ->update(['content' => $html]);
        }
    }

    public function down(): void
    {
        // Nothing to reverse: removing the copy would leave the pages blanker
        // than it found them, and an admin may have edited it since.
    }

    /** @return array<string,string> */
    private function copy(): array
    {
        return [
            'music' => '<h2>Live music &amp; gigs in Malaysia</h2><p>From intimate acoustic sets in Bangsar cafés to festival line-ups in Shah Alam, Malaysia\'s live music scene runs every night of the week. Browse upcoming concerts, album launches, open mics and club nights, and book a ticket in seconds — your QR pass is ready at the door.</p><p>Independent promoters and venues list here alongside the bigger names, so it is as good a place to find a new band in a 60-seat room as it is to catch a touring act.</p>',

            'business' => '<h2>Business events, talks &amp; networking</h2><p>Conferences, founder meetups, industry panels and workshops across Kuala Lumpur, Penang and Johor. Whether you are raising, hiring or just want to be in the room, these are the events where the conversations actually happen.</p><p>Most listings include the agenda and speaker line-up, so you can see what you are signing up for before you commit an afternoon to it.</p>',

            'food-drink' => '<h2>Food &amp; drink events in Malaysia</h2><p>Tasting menus, night markets, coffee cuppings, supper clubs and food festivals — the part of Malaysian culture nobody needs convincing about. Find one-off collaborations between chefs, seasonal menus and the street-food festivals that take over a whole neighbourhood for a weekend.</p><p>Many have limited covers, so booking ahead is usually the difference between going and hearing about it afterwards.</p>',

            'tech' => '<h2>Tech meetups, demos &amp; hackathons</h2><p>Developer meetups, product demos, AI and data sessions, and weekend hackathons across the Klang Valley and beyond. Most are free or close to it, run by communities rather than conference organisers, and are the fastest way to meet people building the same things you are.</p><p>New to a stack? Look for the beginner-friendly sessions — organisers usually say so in the description.</p>',

            'community' => '<h2>Community events &amp; local get-togethers</h2><p>Neighbourhood gatherings, volunteer days, language exchanges, board game nights and the small recurring meetups that hold a city together. These are the events where turning up alone is completely normal.</p><p>Hosting something yourself? Community events are free to list, and you can take RSVPs without charging a cent.</p>',

            'sports' => '<h2>Sports &amp; fitness events</h2><p>Fun runs, futsal leagues, cycling groups, climbing sessions and tournaments across Malaysia. Find something at your level, from a first 5K to a competitive league, and see the capacity before you sign up.</p><p>Most organisers list the venue, the format and what to bring, so there are no surprises on the day.</p>',

            'arts' => '<h2>Arts, theatre &amp; creative workshops</h2><p>Gallery openings, stage productions, film screenings, pottery and printmaking workshops — Malaysia\'s creative scene, in one place. Workshops usually cap numbers tightly, so seats go quickly.</p><p>Browse by city to find what is on near you this weekend, or look further ahead for the bigger seasonal programmes.</p>',

            'wellness' => '<h2>Wellness, yoga &amp; retreats</h2><p>Yoga and breathwork classes, sound baths, meditation sessions and weekend retreats in the highlands and along the coast. Whether you want an hour after work or a full reset, sessions here range from drop-in classes to multi-day programmes.</p><p>Check the listing for what is provided — mats, meals and transport vary by organiser.</p>',
        ];
    }
};

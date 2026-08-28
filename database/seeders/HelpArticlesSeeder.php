<?php

namespace Database\Seeders;

use App\Models\HelpArticle;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/** Default help-center content (editable later under Admin → Help center). */
class HelpArticlesSeeder extends Seeder
{
    public function run(): void
    {
        $articles = [
            ['Getting started', 'What is DropRSVP?', 'A quick overview of what you can do with DropRSVP.',
                '<p>DropRSVP is an all-in-one platform to discover events, sell tickets, manage seating and check guests in. Browse events near you, buy tickets in seconds, and get a QR pass for entry.</p>'],
            ['Getting started', 'Creating your organizer account', 'Sign up and start hosting in minutes.',
                '<p>Click <strong>Sign up</strong>, enter your email, and confirm the 6-digit code we send you. Add your name and a password, and you’re ready to create your first event.</p>'],
            ['Buying tickets', 'How to buy a ticket', 'Find an event and check out securely.',
                '<p>Open an event, choose your ticket type and quantity, then continue to checkout. Enter your details and pay securely — your tickets and a QR pass arrive by email instantly.</p>'],
            ['Buying tickets', 'Finding your tickets', 'Re-download or re-show your tickets any time.',
                '<p>Sign in and open <strong>My tickets</strong> to see every order. Tap a ticket to show its QR pass at the door, print/save it, or re-send it to your email.</p>'],
            ['Buying tickets', 'Refunds', 'How refunds work.',
                '<p>Refunds are issued by the event organizer. If eligible, the amount is returned to your original payment method and the ticket is voided.</p>'],
            ['Organizing events', 'Creating an event', 'Build your event and add tickets.',
                '<p>From your dashboard, click <strong>Create event</strong>. Add a title, cover image, date, location, then set up your ticket types (free or paid, with quantities and limits). Publish when ready.</p>'],
            ['Organizing events', 'Seating & tables', 'Assign attendees to tables.',
                '<p>Open your event’s <strong>Seating</strong> tab to create tables with capacities, then assign attendees. Each guest’s table shows on their QR pass.</p>'],
            ['Organizing events', 'Checking guests in', 'Scan tickets at the door.',
                '<p>Use the <strong>Check-in</strong> console on the event to scan QR passes with a phone camera or USB scanner. Valid tickets flip to “checked in” instantly.</p>'],
            ['Payments & payouts', 'Getting paid', 'How payouts work for organizers.',
                '<p>Ticket revenue (minus the platform fee) becomes available in your <strong>Payouts</strong> balance. Request a payout and the DropRSVP team marks it paid to your bank.</p>'],
            ['Account & security', 'Resetting your password', 'Recover access to your account.',
                '<p>On the log-in screen, choose <strong>Forgot password</strong> and follow the emailed link to set a new password.</p>'],
        ];

        foreach ($articles as $i => [$category, $title, $excerpt, $body]) {
            HelpArticle::updateOrCreate(
                ['slug' => Str::slug($title)],
                ['category' => $category, 'title' => $title, 'excerpt' => $excerpt, 'body' => $body, 'sort' => $i, 'status' => 'published', 'published_at' => now()],
            );
        }
    }
}

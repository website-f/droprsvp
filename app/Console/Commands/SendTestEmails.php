<?php

namespace App\Console\Commands;

use App\Mail\ContactMessageMail;
use App\Mail\OrderRefundedMail;
use App\Mail\OrganizerApplicationMail;
use App\Mail\OrganizerApplicationReceivedMail;
use App\Mail\PayoutPaidMail;
use App\Mail\RegistrationCodeMail;
use App\Mail\TicketsIssued;
use App\Mail\WelcomeMail;
use App\Models\ContactMessage;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrganizerProfile;
use App\Models\Payout;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Sends every transactional email to a target address with realistic in-memory
 * sample data — nothing is written to the database. Use it to verify the SMTP
 * setup + that each template renders and delivers.
 *
 *   php artisan mail:test gitdev1234@gmail.com
 */
class SendTestEmails extends Command
{
    protected $signature = 'mail:test {email : Where to send the samples} {--url=https://www.droprsvp.com : Base URL for links in the emails}';

    protected $description = 'Send one of every transactional email (sample data) to an address to test SMTP + templates';

    public function handle(): int
    {
        $to = $this->argument('email');

        // Links in the email body must use the real production domain — otherwise
        // (in dev) they'd point at droprsvp.test, which some spam filters block.
        $baseUrl = rtrim((string) $this->option('url'), '/');
        config(['app.url' => $baseUrl]);
        URL::forceRootUrl($baseUrl);
        if (str_starts_with($baseUrl, 'https://')) {
            URL::forceScheme('https');
        }

        $this->info("Sending sample emails to {$to} via ".config('mail.mailers.smtp.host')." (links → {$baseUrl}) …");
        $this->newLine();

        // ---- build in-memory sample data (never persisted) -------------------
        $user = new User(['name' => 'Alex Tan', 'email' => $to]);

        $event = new Event();
        $event->forceFill([
            'title' => 'Neon Nights: Rooftop Live',
            'timezone' => 'Asia/Kuala_Lumpur',
            'venue_name' => 'Skyline Rooftop, Kuala Lumpur',
            'is_online' => false,
            'starts_at' => now()->addDays(12)->setTime(20, 0),
        ]);

        $order = new Order();
        $order->forceFill(['reference' => 'DRSVP-TEST01', 'buyer_name' => 'Alex Tan', 'buyer_email' => $to, 'total' => 240.00, 'currency' => 'MYR']);
        $order->setRelation('event', $event);

        $tt = new TicketType(['name' => 'VIP Standing']);
        $mkTicket = function (string $seat) use ($tt) {
            $t = new Ticket();
            $t->forceFill(['attendee_name' => 'Alex Tan', 'seat_label' => $seat, 'qr_token' => (string) Str::ulid()]);
            $t->setRelation('ticketType', $tt);

            return $t;
        };
        $order->setRelation('tickets', collect([$mkTicket('VIP · A1'), $mkTicket('VIP · A2')]));

        $payout = new Payout();
        $payout->forceFill(['reference' => 'PO-TEST01', 'amount' => 318.00, 'currency' => 'MYR', 'method' => 'CHIP Send']);

        $profile = new OrganizerProfile();
        $profile->forceFill(['review_reason' => 'Please add a clearer business logo and a short bio.']);
        $profile->setRelation('user', new User(['name' => 'Aisyah Rahman']));

        $contact = new ContactMessage();
        $contact->forceFill([
            'name' => 'Jamie Lee', 'email' => 'jamie@example.com', 'phone' => '+60 12-345 6789',
            'category' => 'support', 'message' => 'Hi, I bought two tickets but only received one email — can you help?',
        ]);

        // ---- the full set of transactional emails ----------------------------
        $samples = [
            'Welcome (on register / Google sign-up)' => new WelcomeMail($user),
            'Registration code (organizer sign-up)' => new RegistrationCodeMail('482913'),
            'Tickets issued (after purchase)' => new TicketsIssued($order),
            'Order refunded' => new OrderRefundedMail($order),
            'Payout paid (organizer)' => new PayoutPaidMail($payout),
            'Vendor application — received' => new OrganizerApplicationReceivedMail($profile),
            'Organizer application — approved' => new OrganizerApplicationMail($profile, true),
            'Organizer application — needs changes' => new OrganizerApplicationMail($profile, false),
            'Contact message (to support inbox)' => new ContactMessageMail($contact),
        ];

        $ok = 0;
        $failed = 0;
        foreach ($samples as $label => $mailable) {
            try {
                Mail::to($to)->send($mailable);
                $this->line("  <fg=green>✓</> {$label}");
                $ok++;
            } catch (\Throwable $e) {
                $this->line("  <fg=red>✗</> {$label}");
                $this->line("      <fg=red>{$e->getMessage()}</>");
                $failed++;
            }
        }

        $this->newLine();
        $this->info("Done — {$ok} sent, {$failed} failed.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}

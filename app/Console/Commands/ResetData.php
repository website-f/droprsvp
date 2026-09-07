<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

/**
 * Wipe all dummy / transactional data to hand the platform over clean, while
 * keeping the required seed content (event categories, help articles, legal
 * pages, CMS, menus and every settings-based premade text — landing/events SEO
 * blocks, footer, branding, robots, etc.). Leaves a single superadmin account.
 */
class ResetData extends Command
{
    protected $signature = 'droprsvp:reset-data
        {--force : Skip the confirmation prompt}
        {--email=contact@droprsvp.com : Superadmin email to create}
        {--password=droprsvp2026! : Superadmin password}';

    protected $description = 'Clear dummy users, events and transactions (keeps categories, seeders and premade texts) and create a fresh superadmin.';

    /** User-generated + transactional tables to empty. Content/config tables are kept. */
    private array $wipe = [
        // Ticketing & money
        'ticket_transfers', 'tickets', 'order_items', 'orders', 'payouts', 'refund_requests',
        'discount_codes', 'promotions', 'subscriptions',
        // Events & everything hanging off them
        'seats', 'seating_tables', 'seat_sections', 'seat_templates', 'event_props',
        'event_daily_stats', 'event_reviews', 'event_comments', 'event_photos', 'event_sessions',
        'ticket_types', 'seo_meta', 'events',
        // People & their activity
        'follows', 'waitlist_entries', 'contact_messages', 'app_notifications',
        'team_members', 'registration_codes', 'organizer_posts', 'organizer_profiles',
        'passkeys', 'ticket_transfers',
        // Auth artifacts + role pivots (re-created for the new superadmin)
        'sessions', 'password_reset_tokens', 'model_has_roles', 'model_has_permissions',
        'users',
    ];

    public function handle(): int
    {
        $this->warn('This will PERMANENTLY delete all users, events, tickets, orders and other transactional data.');
        $this->line('Kept: roles, event categories, help articles, legal/CMS pages, menus and all premade settings (landing/events SEO, footer, branding, robots).');

        if (! $this->option('force') && ! $this->confirm('Proceed with wiping dummy data?')) {
            $this->info('Aborted — nothing was changed.');

            return self::SUCCESS;
        }

        $email = (string) $this->option('email');
        $password = (string) $this->option('password');

        Schema::disableForeignKeyConstraints();

        foreach (array_unique($this->wipe) as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->truncate();
                $this->line("  cleared <fg=yellow>{$table}</>");
            }
        }

        Schema::enableForeignKeyConstraints();

        // Ensure the roles exist (truncating pivots doesn't drop the roles table),
        // then create the fresh superadmin.
        foreach (['superadmin', 'organizer', 'staff', 'buyer'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        $admin = User::create([
            'name' => 'DropRSVP Admin',
            'email' => $email,
            'password' => Hash::make($password),
            'email_verified_at' => now(),
            'profile_completed_at' => now(),
        ]);
        $admin->syncRoles(['superadmin']);

        $this->newLine();
        $this->info("Done. Superadmin ready: {$email}");

        return self::SUCCESS;
    }
}

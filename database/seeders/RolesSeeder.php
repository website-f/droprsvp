<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * DropRSVP roles + a first superadmin (the platform owner / My Hub).
 *  - superadmin : the client — everything, incl. the CMS
 *  - organizer  : a host — own events, tickets, seating, attendees, payouts
 *  - staff      : check-in only, scoped to assigned events
 *  - buyer      : ticket buyer / attendee
 * Idempotent (firstOrCreate).
 */
class RolesSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['superadmin', 'organizer', 'staff', 'buyer'] as $name) {
            Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $admin = User::firstOrCreate(
            ['email' => 'admin@droprsvp.test'],
            ['name' => 'DropRSVP Admin', 'password' => Hash::make('password')],
        );
        $admin->syncRoles(['superadmin']);
    }
}

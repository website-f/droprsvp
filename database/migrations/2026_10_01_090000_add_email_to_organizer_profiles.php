<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A contact email on the vendor application.
 *
 * The form asked for a business name, a phone and some optional extras — but
 * never an email, so reviewers had only the account's sign-in address, which
 * for a business is very often a personal one and not where they want to be
 * contacted about their events. The application is also the one place we speak
 * to an applicant before they are approved, and "we'll be in touch by email or
 * phone" was promising something the form had not collected.
 *
 * Backfilled from the account address rather than left null, so existing
 * applications are not retroactively incomplete and the reviewer still sees
 * something real for them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('organizer_profiles') || Schema::hasColumn('organizer_profiles', 'email')) {
            return;
        }

        Schema::table('organizer_profiles', function (Blueprint $table) {
            $table->string('email')->nullable()->after('business_name');
        });

        // The account address is the best answer we already hold for an
        // application that predates the field.
        DB::table('organizer_profiles')
            ->whereNull('email')
            ->update([
                'email' => DB::raw('(select users.email from users where users.id = organizer_profiles.user_id)'),
            ]);
    }

    public function down(): void
    {
        if (Schema::hasTable('organizer_profiles') && Schema::hasColumn('organizer_profiles', 'email')) {
            Schema::table('organizer_profiles', fn (Blueprint $table) => $table->dropColumn('email'));
        }
    }
};

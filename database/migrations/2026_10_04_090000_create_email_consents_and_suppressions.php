<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who we may send marketing email to, and who we must never send to again.
 *
 * Until now nothing recorded marketing consent at all. The checkout and
 * register switch is pre-ticked, required to continue, and covers "manage your
 * RSVP and provide event updates" — agreement to the service, not permission
 * to promote other events. So every EDM recipient has to come from here.
 *
 * Consent is SCOPED. "platform" is DropRSVP's own newsletter; "organizer:12"
 * is one organizer's list. Agreeing to hear from DropRSVP is not agreeing to
 * hear from every organizer on it, and unsubscribing from one organizer must
 * not silence the others — so it is one row per address per scope.
 *
 * The scope is a string rather than a nullable organizer_id because a unique
 * index over a NULL column does not enforce uniqueness in MySQL: two
 * "platform" rows for the same address would both be allowed.
 *
 * Suppressions are the other half: addresses that hard-bounced, complained or
 * were blocked by hand. Those are about deliverability, not preference, so they
 * apply across every scope — mailing a dead address from any list hurts the
 * same sending reputation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_consents', function (Blueprint $table) {
            $table->id();
            $table->string('email', 191);
            $table->string('scope', 40)->default('platform');           // platform | organizer:{id}
            $table->foreignId('organizer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20);                               // subscribed | unsubscribed
            $table->string('source', 40);                               // checkout | register | settings | repermission | unsubscribe | admin
            $table->timestamp('consented_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            // Where the decision was made, kept as evidence: PDPA puts the
            // burden of showing consent on whoever relies on it.
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->unique(['email', 'scope']);
            $table->index(['scope', 'status']);
            $table->index('user_id');
        });

        Schema::create('email_suppressions', function (Blueprint $table) {
            $table->id();
            $table->string('email', 191)->unique();
            $table->string('reason', 20);                               // bounce | complaint | manual
            $table->string('detail', 255)->nullable();                  // the server's own words, for a human to read
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_suppressions');
        Schema::dropIfExists('email_consents');
    }
};

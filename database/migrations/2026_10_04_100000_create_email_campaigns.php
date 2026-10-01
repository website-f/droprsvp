<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campaigns, and one row per recipient per campaign.
 *
 * email_sends doubles as the send queue. Production runs no queue worker —
 * transactional mail goes out through defer() — so rather than introduce one
 * and push thousands of job rows through the jobs table, a scheduled command
 * claims the next batch of 'queued' sends each minute, up to the hourly
 * budget. The table is then also the delivery log and the source of every
 * statistic, with nothing to reconcile between a queue and a report.
 *
 * Each send carries its own random token: the open pixel, the click redirect,
 * "view in browser" and unsubscribe all identify the recipient by it, so no
 * email address or user id ever appears in a URL inside an email.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_campaigns', function (Blueprint $table) {
            $table->id();
            // Null = DropRSVP's own newsletter; otherwise the organizer whose
            // list this goes to (Phase 2). Mirrors email_consents.scope.
            $table->foreignId('organizer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 20)->default('standard');           // standard | repermission
            $table->string('name', 160);
            $table->string('subject', 200)->default('');
            $table->string('preheader', 200)->nullable();
            $table->string('from_name', 120)->nullable();
            $table->string('reply_to', 191)->nullable();
            $table->json('design')->nullable();                         // editor blocks
            $table->json('audience')->nullable();                       // segment filters
            // Frozen when the campaign starts sending, so an event edited later
            // cannot rewrite mail that has already gone out.
            $table->longText('html')->nullable();
            $table->longText('text')->nullable();
            $table->string('status', 20)->default('draft');            // draft | scheduled | sending | paused | sent | cancelled
            $table->string('paused_reason', 255)->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            // Counters, maintained as sends change state, so the campaign list
            // never has to aggregate email_sends to show a number.
            $table->unsignedInteger('recipients_count')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('opened_count')->default(0);
            $table->unsignedInteger('clicked_count')->default(0);
            $table->unsignedInteger('bounced_count')->default(0);
            $table->unsignedInteger('unsubscribed_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'scheduled_at']);
            $table->index('organizer_id');
        });

        Schema::create('email_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('email_campaigns')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email', 191);
            $table->string('name', 160)->nullable();                    // for {{first_name}}, snapshotted
            $table->char('token', 40)->unique();
            $table->string('status', 20)->default('queued');           // queued | sent | failed | bounced | skipped
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('error', 255)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->unsignedInteger('open_count')->default(0);
            $table->timestamp('clicked_at')->nullable();
            $table->unsignedInteger('click_count')->default(0);
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamps();

            // One copy per address per campaign, however the audience was built.
            $table->unique(['campaign_id', 'email']);
            // The dispatcher's query: next queued sends, oldest first.
            $table->index(['status', 'id']);
            // The throttle's query: how many went out in the last hour.
            $table->index('sent_at');
        });

        Schema::create('email_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('email_campaigns')->cascadeOnDelete();
            $table->char('hash', 40);                                   // sha1 of the url
            $table->text('url');
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('unique_clicks')->default(0);
            $table->timestamps();

            $table->unique(['campaign_id', 'hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_links');
        Schema::dropIfExists('email_sends');
        Schema::dropIfExists('email_campaigns');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marketing automation: sequences that send themselves.
 *
 * edm_automations       one sequence, owned by DropRSVP or an organizer, with
 *                       a trigger (event reminder, post-event, abandoned
 *                       checkout, welcome) and on/off.
 * edm_automation_steps  its emails in order, each with a timing relative to the
 *                       trigger moment ("3 days before the event", "1 hour
 *                       after they left checkout") and conditions. Each step
 *                       owns a hidden EmailCampaign (kind = automation), so
 *                       sending, tracking, links, unsubscribe and every
 *                       per-step statistic reuse the campaign machinery.
 * edm_enrollments       one person moving through one sequence for one reason
 *                       (this event, this order, this subscription) — unique
 *                       on that reason, so nobody is enrolled twice.
 *
 * email_sends.dedupe widens "one copy per person per campaign" so a step can
 * mail the same person once per enrollment (two events, two reminders).
 * Ordinary campaigns keep dedupe = '' and so keep their guarantee.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edm_automations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizer_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('trigger', 24);                       // event_reminder | post_event | abandoned_checkout | welcome
            $table->string('name', 160);
            $table->string('status', 12)->default('draft');     // draft | active | paused
            $table->json('settings')->nullable();                // e.g. event_ids to limit to
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'trigger']);
            $table->index('organizer_id');
        });

        Schema::create('edm_automation_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_id')->constrained('edm_automations')->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained('email_campaigns')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->integer('delay_value')->default(0);
            $table->string('delay_unit', 8)->default('days');   // minutes | hours | days
            $table->string('delay_direction', 8)->default('after'); // before | after
            $table->json('conditions')->nullable();
            $table->unsignedInteger('skipped_count')->default(0);
            $table->timestamps();
        });

        Schema::create('edm_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_id')->constrained('edm_automations')->cascadeOnDelete();
            $table->string('email', 191);
            $table->string('name', 160)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('context_key', 40);                   // event:12 | order:55 | welcome
            $table->json('context')->nullable();                 // event_id, order_id …
            $table->timestamp('anchor_at');                      // the trigger moment
            $table->string('status', 12)->default('active');    // active | completed | exited
            $table->unsignedSmallInteger('next_step')->default(0);
            $table->timestamp('next_at')->nullable();
            $table->string('exit_reason', 120)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['automation_id', 'email', 'context_key']);
            $table->index(['status', 'next_at']);
        });

        Schema::table('email_sends', function (Blueprint $table) {
            $table->json('context')->nullable()->after('name');  // {{event_name}} and friends
            $table->string('dedupe', 40)->default('')->after('context');
        });

        Schema::table('email_sends', function (Blueprint $table) {
            $table->dropUnique(['campaign_id', 'email']);
            $table->unique(['campaign_id', 'email', 'dedupe']);
        });
    }

    public function down(): void
    {
        Schema::table('email_sends', function (Blueprint $table) {
            $table->dropUnique(['campaign_id', 'email', 'dedupe']);
            $table->unique(['campaign_id', 'email']);
        });
        Schema::table('email_sends', function (Blueprint $table) {
            $table->dropColumn(['context', 'dedupe']);
        });
        Schema::dropIfExists('edm_enrollments');
        Schema::dropIfExists('edm_automation_steps');
        Schema::dropIfExists('edm_automations');
    }
};

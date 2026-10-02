<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EDM deliverability: bounces, saved templates, and health checks.
 *
 * email_bounces is the evidence behind every suppression and every campaign's
 * bounce count — one row per bounce or complaint read from the return mailbox,
 * kept even after the address is suppressed so the reason can be shown.
 *
 * email_templates are reusable designs, separate from campaigns: a campaign is
 * one send, a template is a starting point for many.
 *
 * edm_checks keeps the latest result of each automated check (DNS records,
 * blocklists) with when it last changed, so the admin sees "listed since
 * Tuesday" rather than only "listed".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_bounces', function (Blueprint $table) {
            $table->id();
            $table->string('email', 191);
            $table->foreignId('send_id')->nullable()->constrained('email_sends')->nullOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained('email_campaigns')->nullOnDelete();
            $table->string('type', 12);                                  // hard | soft | blocked | complaint
            $table->string('status_code', 12)->nullable();               // e.g. 5.1.1
            $table->string('diagnostic', 500)->nullable();               // the receiving server's own words
            $table->string('message_id', 191)->nullable();               // the bounce's own Message-ID, to skip re-reads
            $table->timestamps();

            $table->index(['email', 'created_at']);
            $table->index(['campaign_id', 'type']);
            $table->unique(['message_id', 'email']);
        });

        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            // Null = DropRSVP's own library; otherwise the organizer's (Phase 2).
            $table->foreignId('organizer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 160);
            $table->string('description', 255)->nullable();
            $table->string('subject', 200)->nullable();                  // suggested subject, copied into new campaigns
            $table->string('preheader', 200)->nullable();
            $table->json('design');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('organizer_id');
        });

        Schema::create('edm_checks', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20);                                  // dns | blocklist
            $table->string('target', 191);                               // domain or IP checked
            $table->string('name', 80);                                  // spf | dkim | dmarc | zen.spamhaus.org …
            $table->string('status', 12);                                // pass | warn | fail | listed | clean | unknown
            $table->text('detail')->nullable();
            $table->timestamp('checked_at');
            $table->timestamp('changed_at');                             // when status last differed
            $table->timestamps();

            $table->unique(['kind', 'target', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edm_checks');
        Schema::dropIfExists('email_templates');
        Schema::dropIfExists('email_bounces');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EDM for organizers: their standing, their email credits, how they bought
 * them, and the domains they may send from.
 *
 * edm_accounts      one per organizer who has used EDM — active or suspended
 *                   (by the abuse guardrails or a superadmin), with an optional
 *                   monthly-allowance override.
 * edm_credit_ledger every change to what an organizer may send, as signed
 *                   rows: purchases and grants add, a campaign's reservation
 *                   subtracts, its unsent remainder is refunded. Two pools:
 *                   "allowance" (free monthly, premium organizers; per period,
 *                   never rolls over) and "credits" (bought; never expire).
 *                   The balance is always a SUM — nothing to drift.
 * edm_credit_purchases a credit pack bought through CHIP.
 * edm_sending_domains an organizer's own From domain, with the DKIM key the
 *                   app signs their mail with once DNS proves they own it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edm_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizer_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('status', 12)->default('active');               // active | suspended
            $table->string('suspended_reason', 255)->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedInteger('monthly_allowance')->nullable();      // null = the default for their plan
            $table->timestamps();
        });

        Schema::create('edm_credit_purchases', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 32)->unique();
            $table->foreignId('organizer_id')->constrained('users')->cascadeOnDelete();
            $table->string('pack', 20);
            $table->unsignedInteger('credits');
            $table->decimal('amount', 10, 2);
            $table->string('status', 12)->default('pending');              // pending | paid | failed
            $table->string('payment_ref')->nullable();
            $table->string('payment_method', 40)->nullable();
            $table->string('payment_brand', 60)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['organizer_id', 'status']);
        });

        Schema::create('edm_credit_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizer_id')->constrained('users')->cascadeOnDelete();
            $table->string('pool', 10);                                    // allowance | credits
            $table->char('period', 7)->nullable();                         // YYYY-MM, allowance rows only
            $table->integer('delta');
            $table->string('reason', 12);                                  // purchase | reserve | refund | adjust
            $table->foreignId('campaign_id')->nullable()->constrained('email_campaigns')->nullOnDelete();
            $table->foreignId('purchase_id')->nullable()->constrained('edm_credit_purchases')->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organizer_id', 'pool', 'period']);
        });

        Schema::create('edm_sending_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizer_id')->constrained('users')->cascadeOnDelete();
            $table->string('domain', 191)->unique();
            $table->string('selector', 40)->default('droprsvp');
            $table->text('dkim_private');                                  // encrypted at rest
            $table->text('dkim_public');
            $table->string('verify_token', 64);
            $table->string('status', 12)->default('pending');              // pending | verified | failed
            $table->json('checks')->nullable();                            // last result per record
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });

        Schema::table('email_campaigns', function (Blueprint $table) {
            // What a campaign took from its organizer's quota when it started,
            // so exactly the unsent remainder can be handed back.
            $table->unsignedInteger('allowance_reserved')->default(0)->after('unsubscribed_count');
            $table->unsignedInteger('credits_reserved')->default(0)->after('allowance_reserved');
            $table->timestamp('credits_settled_at')->nullable()->after('credits_reserved');
            // An organizer's own From address on a verified domain.
            $table->string('from_address', 191)->nullable()->after('from_name');
            $table->foreignId('sending_domain_id')->nullable()->after('from_address')->constrained('edm_sending_domains')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('email_campaigns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sending_domain_id');
            $table->dropColumn(['allowance_reserved', 'credits_reserved', 'credits_settled_at', 'from_address']);
        });
        Schema::dropIfExists('edm_sending_domains');
        Schema::dropIfExists('edm_credit_ledger');
        Schema::dropIfExists('edm_credit_purchases');
        Schema::dropIfExists('edm_accounts');
    }
};

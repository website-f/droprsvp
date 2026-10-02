<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In-app chat: one-to-one conversations between users, with message requests,
 * blocking, reporting, moderation and admin broadcasts.
 *
 * chat_conversations  one per pair of users (low id, high id — unique), with
 *                     its standing: active, a request awaiting acceptance, or
 *                     a declined request.
 * chat_participants   each side's own view of it: how far they have read, an
 *                     unread counter kept up to date as messages arrive (so
 *                     badges never aggregate the messages table), and whether
 *                     they have hidden it from their inbox.
 * chat_messages       text and/or one image; soft-removed by the sender
 *                     ("unsend") or a moderator; the sender's IP kept for abuse
 *                     handling.
 * chat_blocks / chat_reports / chat_ip_bans  safety.
 * chat_user_states    a user's chat standing (suspension), how far they have
 *                     read the announcements, and the unread-email bookkeeping.
 * chat_broadcasts     admin announcements, stored once and shown to everyone
 *                     in the audience as a pinned thread.
 *
 * Every step guarded, so a run interrupted part-way on MySQL (which does not
 * roll back DDL) can simply be run again.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('chat_conversations')) {
            Schema::create('chat_conversations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_low_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('user_high_id')->constrained('users')->cascadeOnDelete();
                $table->string('status', 10)->default('active');           // active | request | declined
                $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('accepted_at')->nullable();
                $table->unsignedBigInteger('last_message_id')->nullable();
                $table->timestamp('last_message_at')->nullable();
                $table->timestamps();

                $table->unique(['user_low_id', 'user_high_id']);
                $table->index('last_message_at');
            });
        }

        if (! Schema::hasTable('chat_participants')) {
            Schema::create('chat_participants', function (Blueprint $table) {
                $table->id();
                $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('other_user_id')->constrained('users')->cascadeOnDelete();
                $table->unsignedBigInteger('last_read_message_id')->default(0);
                $table->unsignedInteger('unread_count')->default(0);
                $table->timestamp('hidden_at')->nullable();
                $table->timestamps();

                $table->unique(['conversation_id', 'user_id']);
                $table->index(['user_id', 'unread_count']);
            });
        }

        if (! Schema::hasTable('chat_messages')) {
            Schema::create('chat_messages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
                $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
                $table->text('body')->nullable();
                $table->string('image_path', 255)->nullable();
                $table->json('image_meta')->nullable();                    // width, height, size, mime
                $table->string('ip', 45)->nullable();
                $table->timestamp('deleted_at')->nullable();
                $table->boolean('removed_by_admin')->default(false);
                $table->timestamps();

                $table->index(['conversation_id', 'id']);
                $table->index(['sender_id', 'created_at']);
            });
        }

        if (! Schema::hasTable('chat_blocks')) {
            Schema::create('chat_blocks', function (Blueprint $table) {
                $table->id();
                $table->foreignId('blocker_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('blocked_id')->constrained('users')->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['blocker_id', 'blocked_id']);
            });
        }

        if (! Schema::hasTable('chat_reports')) {
            Schema::create('chat_reports', function (Blueprint $table) {
                $table->id();
                $table->foreignId('reporter_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('reported_user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('conversation_id')->nullable()->constrained('chat_conversations')->nullOnDelete();
                $table->foreignId('message_id')->nullable()->constrained('chat_messages')->nullOnDelete();
                $table->string('reason', 30);
                $table->text('details')->nullable();
                $table->string('status', 12)->default('open');             // open | dismissed | actioned
                $table->string('action', 60)->nullable();
                $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('handled_at')->nullable();
                $table->timestamps();

                $table->index('status');
            });
        }

        if (! Schema::hasTable('chat_user_states')) {
            Schema::create('chat_user_states', function (Blueprint $table) {
                $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
                $table->timestamp('suspended_until')->nullable();
                $table->boolean('suspended_forever')->default(false);
                $table->string('suspended_reason', 255)->nullable();
                $table->unsignedBigInteger('broadcast_read_id')->default(0);
                $table->unsignedBigInteger('notified_upto_message_id')->default(0);
                $table->timestamp('last_notified_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('chat_ip_bans')) {
            Schema::create('chat_ip_bans', function (Blueprint $table) {
                $table->id();
                $table->string('ip', 45)->unique();
                $table->string('reason', 255)->nullable();
                $table->timestamp('until')->nullable();                     // null = permanent
                $table->boolean('automatic')->default(false);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('chat_broadcasts')) {
            Schema::create('chat_broadcasts', function (Blueprint $table) {
                $table->id();
                $table->string('title', 160);
                $table->text('body');
                $table->string('audience', 20)->default('all');            // all | organizers | buyers
                $table->unsignedInteger('recipients')->default(0);
                $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_broadcasts');
        Schema::dropIfExists('chat_ip_bans');
        Schema::dropIfExists('chat_user_states');
        Schema::dropIfExists('chat_reports');
        Schema::dropIfExists('chat_blocks');
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_participants');
        Schema::dropIfExists('chat_conversations');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            // An organizer's appeal against a cancellation: null | pending | dismissed.
            $table->string('appeal_status', 16)->nullable()->after('cancelled_reason');
            $table->text('appeal_reason')->nullable()->after('appeal_status');
            $table->json('appeal_attachments')->nullable()->after('appeal_reason'); // proof image/file URLs
            $table->timestamp('appealed_at')->nullable()->after('appeal_attachments');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['appeal_status', 'appeal_reason', 'appeal_attachments', 'appealed_at']);
        });
    }
};

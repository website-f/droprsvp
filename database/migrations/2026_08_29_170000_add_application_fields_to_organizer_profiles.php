<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizer_profiles', function (Blueprint $table) {
            $table->string('status')->nullable()->after('user_id'); // pending | approved | rejected
            $table->string('business_name')->nullable();
            $table->string('website')->nullable();
            $table->string('phone')->nullable();
            $table->text('bio')->nullable();
            $table->string('poster')->nullable();
            $table->json('gallery')->nullable();
            $table->text('review_reason')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
        });

        // Grandfather existing organizers — they're already approved.
        DB::table('organizer_profiles')->update(['status' => 'approved']);
    }

    public function down(): void
    {
        Schema::table('organizer_profiles', function (Blueprint $table) {
            $table->dropColumn(['status', 'business_name', 'website', 'phone', 'bio', 'poster', 'gallery', 'review_reason', 'submitted_at', 'reviewed_at']);
        });
    }
};

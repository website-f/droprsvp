<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizer_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            // Onboarding answers — all optional (the flow is skippable).
            $table->json('event_types')->nullable();       // e.g. ["music","business"]
            $table->string('revenue_band')->nullable();     // expected revenue bracket
            $table->string('events_per_year')->nullable();  // planned events next 12 months
            $table->string('audience_size')->nullable();    // typical crowd size
            $table->string('age_range')->nullable();        // typical attendee age
            $table->timestamp('completed_at')->nullable();  // finished/skipped onboarding
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizer_profiles');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Discussion wall on an organizer's public profile — questions + threaded replies. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizer_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizer_id')->constrained('users')->cascadeOnDelete(); // whose wall
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();       // author
            $table->foreignId('parent_id')->nullable()->constrained('organizer_posts')->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizer_posts');
    }
};

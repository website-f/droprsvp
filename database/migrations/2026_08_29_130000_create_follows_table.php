<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('follows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('follower_id')->constrained('users')->cascadeOnDelete();   // the fan
            $table->foreignId('organizer_id')->constrained('users')->cascadeOnDelete();  // who they follow
            $table->timestamps();

            $table->unique(['follower_id', 'organizer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follows');
    }
};

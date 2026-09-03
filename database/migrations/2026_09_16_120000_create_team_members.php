<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_members', function (Blueprint $table) {
            $table->id();
            // The account owner and the collaborator they've granted access to.
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('users')->cascadeOnDelete();
            $table->string('role')->default('manager'); // manager (full event management)
            $table->timestamps();

            $table->unique(['owner_id', 'member_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_members');
    }
};

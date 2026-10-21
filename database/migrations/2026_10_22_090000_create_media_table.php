<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * The Media Library: one row per uploaded image, so images can be browsed,
 * searched and reused instead of uploaded again every time.
 *
 * Images were already stored (storage/app/public/cms/) but nothing recorded
 * them, so there was no way to find one again. `path` is relative to the
 * public disk ("cms/abc.jpg"); URLs are worked out from it, which keeps them
 * right if the domain changes. `user_id` is who uploaded it — organizers see
 * only their own; images from before the library have none and are visible
 * to admins.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('media')) {
            Schema::create('media', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('path', 512)->unique();
                $table->string('original_name', 191)->nullable();
                $table->string('alt', 300)->nullable();
                $table->unsignedInteger('width')->nullable();
                $table->unsignedInteger('height')->nullable();
                $table->unsignedInteger('size')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'created_at']);
            });
        }

        // Everything already uploaded goes into the library straight away, so
        // it is not empty on the first visit. Best effort: a deploy must not
        // fail over a file it cannot read, and the command can be run again.
        // Not in the test suite, whose migrations would otherwise read the
        // developer's real upload folder into every test database.
        if (app()->runningUnitTests()) {
            return;
        }

        try {
            Artisan::call('media:import');
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};

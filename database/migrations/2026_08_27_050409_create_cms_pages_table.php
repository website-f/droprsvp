<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CMS pages — WordPress-style content managed by the superadmin and served,
 * server-rendered, at their own root slug (SEO via the polymorphic seo_meta).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->string('slug')->unique();
            $table->longText('body')->nullable();          // sanitised HTML from the editor
            $table->string('status')->default('draft');     // draft | published
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_pages');
    }
};

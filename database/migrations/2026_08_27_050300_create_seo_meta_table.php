<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Polymorphic SEO metadata — one row per page / post / event, edited through the
 * WordPress/Yoast-style SEO panel and rendered server-side into the page head
 * (title / meta / canonical / robots / OG / Twitter / JSON-LD).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_meta', function (Blueprint $table) {
            $table->id();
            $table->morphs('seoable');                       // seoable_type + seoable_id
            $table->string('seo_title')->nullable();
            $table->string('meta_description', 320)->nullable();
            $table->string('slug')->nullable();
            $table->string('focus_keyphrase')->nullable();
            $table->string('canonical_url')->nullable();
            $table->boolean('robots_index')->default(true);
            $table->boolean('robots_follow')->default(true);
            $table->string('og_title')->nullable();
            $table->string('og_description', 320)->nullable();
            $table->string('og_image')->nullable();
            $table->string('twitter_card')->default('summary_large_image');
            $table->string('breadcrumb_title')->nullable();
            $table->string('schema_type')->nullable();       // Article, Event, WebPage, ...
            $table->json('schema_overrides')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_meta');
    }
};

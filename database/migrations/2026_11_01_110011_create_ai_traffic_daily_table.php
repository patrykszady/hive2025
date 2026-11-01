<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The table `SsSystems\Platform\Seo\AiTraffic\AiTrafficStore` reads and
 * writes — copy this stub into your own database/migrations/ with today's
 * date (e.g. 2026_11_01_120000_create_ai_traffic_daily_table.php). Daily
 * counters for AI-driven traffic: humans arriving from AI assistants
 * (kind=referral, e.g. ChatGPT, Perplexity) and AI crawlers fetching pages to
 * build their answers (kind=crawler, e.g. GPTBot, ClaudeBot), one row per
 * site/day/kind/source/path, incremented by `TrackAiTraffic` middleware.
 * Exists so GEO work is measured instead of faith-based — nothing on the
 * SEO Reports dashboard could otherwise say whether ChatGPT/Perplexity ever
 * send anyone, or which pages AI crawlers actually read.
 *
 * Merges gs.construction's two migrations (2026_08_16_201757 create +
 * 2026_08_23_121000 add `path`) into the one shape every new adopter starts
 * from — no site needs the intermediate, path-less version.
 *
 * MULTI-TENANT SITES: `site_id` is nullable/indexed below and part of the
 * unique key already — gs.construction's `TrackAiTraffic`/`AiTrafficStore`
 * pass `tenantColumn: 'site_id'` plus a `tenantId` closure reading the
 * current tenant at call time. A single-tenant site (jpeterson-design,
 * dawnsellshomes) leaves every row's `site_id` null and passes neither.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_traffic_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->nullable()->index();
            $table->date('date');
            $table->string('kind', 16);      // referral | crawler
            $table->string('source', 64);    // chatgpt, perplexity, GPTBot, ClaudeBot, …
            // WHICH pages AI assistants cite and crawl — the one GEO metric no
            // vendor can sell us, since only the origin sees its own referrers.
            // Nullable: a row recorded before a site added per-path tracking
            // (or one that deliberately never does) keeps path NULL.
            $table->string('path', 191)->nullable();
            $table->unsignedInteger('count')->default(0);
            $table->timestamps();

            $table->unique(['site_id', 'date', 'kind', 'source', 'path'], 'ai_traffic_daily_dim_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_traffic_daily');
    }
};

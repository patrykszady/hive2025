<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bing Webmaster Tools crawl stats, one row per day (GetCrawlStats): pages
 * Bing crawled, pages in its index, the errors and status codes it met.
 * Copy this stub into your own database/migrations/ with today's date.
 * Filled by SsSystems\Platform\Seo\Bing\BingCoverageSync through your
 * site's BingCoverageWriter; read by ServesBingCoverage for the hub's Bing
 * card (2026-11-10).
 *
 * MULTI-TENANT SITES: uncomment the `site_id` line below and fold it into
 * the unique index (renaming it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bing_crawl_stats', function (Blueprint $table) {
            $table->id();
            // $table->foreignId('site_id')->nullable()->index(); // multi-tenant sites only
            $table->date('date');
            $table->string('site_url', 191);
            $table->unsignedInteger('crawled_pages')->default(0);
            $table->unsignedInteger('in_index')->default(0);
            $table->unsignedInteger('in_links')->default(0);
            $table->unsignedInteger('crawl_errors')->default(0);
            $table->unsignedInteger('blocked_by_robots')->default(0);
            $table->unsignedInteger('code_2xx')->default(0);
            $table->unsignedInteger('code_301')->default(0);
            $table->unsignedInteger('code_302')->default(0);
            $table->unsignedInteger('code_4xx')->default(0);
            $table->unsignedInteger('code_5xx')->default(0);
            $table->unsignedInteger('other_codes')->default(0);
            $table->unsignedInteger('connection_timeouts')->default(0);
            $table->unsignedInteger('dns_failures')->default(0);
            $table->timestamps();

            $table->unique(['date', 'site_url'], 'bing_crawl_stats_date_site_url_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bing_crawl_stats');
    }
};

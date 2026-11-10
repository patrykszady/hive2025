<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What Bing knows of each sitemap URL (GetUrlInfo: when it found and last
 * fetched it; GetPageStats: whether it showed it in results in the last 28
 * days), one row per URL keyed on url_hash. Copy this stub into your own
 * database/migrations/ with today's date. Filled by SsSystems\Platform\Seo\
 * Bing\BingCoverageSync through your site's BingCoverageWriter; read by
 * ServesBingCoverage for the hub's Bing card (2026-11-10). A URL that left
 * the sitemap keeps its row with in_sitemap = false.
 *
 * MULTI-TENANT SITES: uncomment the `site_id` line below and fold it into
 * the unique index (renaming it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bing_url_info', function (Blueprint $table) {
            $table->id();
            // $table->foreignId('site_id')->nullable()->index(); // multi-tenant sites only
            $table->string('url', 500);
            $table->char('url_hash', 40);
            $table->boolean('in_sitemap')->default(true)->index();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->boolean('is_page')->nullable();
            $table->unsignedInteger('document_size')->nullable();
            $table->unsignedInteger('anchor_count')->nullable();
            $table->unsignedInteger('child_url_count')->nullable();
            $table->date('discovered_on')->nullable();
            $table->timestamp('last_crawled_at')->nullable()->index();
            $table->unsignedInteger('impressions_28d')->default(0);
            $table->unsignedInteger('clicks_28d')->default(0);
            $table->date('last_shown_on')->nullable();
            $table->timestamp('inspected_at')->nullable()->index();
            $table->string('inspect_error')->nullable();
            $table->timestamps();

            $table->unique('url_hash', 'bing_url_info_url_hash_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bing_url_info');
    }
};

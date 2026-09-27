<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Google Search Console URL Inspection results, one row per tracked URL —
 * ported from dawnsellshomes' gsc_coverage_states (itself ported from
 * jpeterson-design/gsc, single-tenant here so no site_id). Filled by
 * seo:gsc-inspect-bulk through the shared ss-systems/platform-kit
 * UrlInspectionSweep — see SsSystems\Platform\Seo\Inspection\Contracts\
 * CoverageStore's docblock for what each column means.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gsc_coverage_states', function (Blueprint $table) {
            $table->id();
            $table->string('url', 500);
            $table->string('source', 20)->default('sitemap')->index();
            $table->string('verdict', 32)->nullable();
            $table->string('coverage_state', 191)->nullable();
            $table->string('console_reason')->nullable();
            $table->string('robots_txt_state', 32)->nullable();
            $table->string('indexing_state', 32)->nullable();
            $table->string('page_fetch_state', 32)->nullable();
            $table->string('sitemap_url', 500)->nullable();
            $table->timestamp('last_crawl_time')->nullable();
            $table->string('user_canonical', 500)->nullable();
            $table->string('google_canonical', 500)->nullable();
            $table->timestamp('inspected_at');
            $table->timestamp('last_changed_at')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamps();

            $table->unique('url', 'gsc_coverage_url_unique');
            $table->index(['verdict', 'coverage_state'], 'gsc_coverage_state_idx');
            $table->index('inspected_at', 'gsc_coverage_inspected_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gsc_coverage_states');
    }
};

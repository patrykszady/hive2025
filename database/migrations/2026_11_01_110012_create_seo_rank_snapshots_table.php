<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Weekly rank snapshots for the tracked queries SsSystems\Platform\Seo\
 * Rankings\Console\TrackRankingsCommand writes — copy this stub into your
 * own database/migrations/ with today's date (e.g.
 * 2026_11_01_120000_create_seo_rank_snapshots_table.php). Columns ported
 * byte-identical from gs.construction's own
 * 2026_05_09_120000_create_seo_rank_snapshots_table migration (the source
 * this whole Rankings module was ported from); `site_id` is new here,
 * nullable and indexed, so a single-tenant site (jpeterson-design,
 * dawnsellshomes) can leave it null and a multi-tenant one (gs.construction
 * itself) can bind RankSnapshotStore with a tenant column/closure.
 *
 * `engine` is 'gsc' (impression-weighted position derived from synced
 * Search Console data) or 'google' (a real DataForSEO SERP check) — see
 * RankingsSnapshot's own docblock for how the two panels differ and why
 * their tracked counts never match.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_rank_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('site_id')->nullable()->index();
            $table->string('engine', 32);              // gsc | google
            $table->string('query');                   // search term
            $table->string('location')->nullable();    // DataForSEO location_name for this check
            $table->string('city_slug')->nullable();   // links to the site's own areas-served, if applicable
            $table->unsignedSmallInteger('gsc_position')->nullable(); // null = not in result set
            $table->string('gsc_match_title')->nullable();            // which listing matched
            $table->unsignedSmallInteger('result_count')->nullable();
            $table->json('top_results')->nullable();   // list<string> of top domains seen on the SERP
            $table->json('meta')->nullable();          // source, matched_url, local_pack_present, fetch_failed, dataforseo_task_id, ...
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->index(['engine', 'query', 'location', 'fetched_at'], 'seo_rank_lookup_idx');
            $table->index(['city_slug', 'fetched_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_rank_snapshots');
    }
};

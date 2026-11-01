<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The table `SsSystems\Platform\Seo\MapPack\MapPackStore` reads and
 * writes — copy this stub into your own database/migrations/ with today's
 * date (e.g. 2026_11_01_120001_create_map_pack_competitors_table.php). One
 * row per business per keyword per scan that `seo:map-pack-grid` saw
 * somewhere on the grid (never the site's own business — see
 * `SeoSiteProfile::brandPatterns()`): its rating/review count/claimed
 * status, and how many grid points it appeared in versus landed in the
 * 3-pack.
 *
 * Merges gs.construction's `local_falcon_competitors` → `map_pack_competitors`
 * history into the one shape every new adopter starts from.
 *
 * site_title/site_description/site_excerpt/site_headings/site_towns/
 * site_services/site_fetched_at are kept, nullable, for forward
 * compatibility with gs.construction's existing rows (its
 * `CompetitorSiteFetcher` reads a competitor's own homepage and fills
 * them in) — this port's `seo:map-pack-competitors` does not read
 * competitor websites (no HTTP-reading contract exists in the kit yet) and
 * never writes these columns; `MapPackSnapshot` degrades gracefully when
 * they are null, same as when a site's `MapPackContext` is the null one.
 *
 * MULTI-TENANT SITES: `site_id` is nullable/indexed below and part of the
 * unique key, exactly like `AiTrafficStore`'s multi-tenant rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('map_pack_competitors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->nullable()->index();
            $table->string('place_id', 64);
            $table->string('keyword', 191);
            $table->string('name');
            $table->string('url', 500)->nullable();
            $table->string('host', 191)->nullable();
            $table->decimal('rating', 3, 2)->nullable();
            $table->unsignedInteger('reviews')->nullable();
            $table->boolean('claimed')->nullable();
            $table->json('categories')->nullable();
            $table->string('scan_id', 64)->nullable();
            $table->timestamp('scanned_at')->nullable();
            $table->unsignedSmallInteger('pack_points')->default(0);
            $table->unsignedSmallInteger('seen_points')->default(0);
            $table->unsignedSmallInteger('best_rank')->nullable();
            $table->string('site_title')->nullable();
            $table->string('site_description', 1000)->nullable();
            $table->text('site_excerpt')->nullable();
            $table->json('site_headings')->nullable();
            $table->json('site_towns')->nullable();
            $table->json('site_services')->nullable();
            $table->timestamp('site_fetched_at')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'place_id', 'keyword']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('map_pack_competitors');
    }
};

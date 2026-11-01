<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The table `SsSystems\Platform\Seo\MapPack\MapPackStore` reads and
 * writes — copy this stub into your own database/migrations/ with today's
 * date (e.g. 2026_11_01_120000_create_map_pack_scans_table.php). One row
 * per keyword per scan: the geo-grid Google Maps results DataForSEO
 * returned, summarised into ARP (average rank where found), ATRP (average
 * rank treating not-found as 21st), SoLV (share of grid points in the
 * 3-pack) and SAIV (share of AI-answer mentions, for a non-Maps platform
 * row) — the same shape the "Map Pack Visibility" report card and
 * `MapPackSnapshot` consume.
 *
 * Merges gs.construction's `local_falcon_scans` → `map_pack_scans` history
 * (create, +platform/+saiv, rename, purge) into the one shape every new
 * adopter starts from.
 *
 * MULTI-TENANT SITES: `site_id` is nullable/indexed below and part of the
 * unique key — a multi-tenant site passes `tenantColumn: 'site_id'` plus a
 * `tenantId` closure to `MapPackStore`, exactly like `AiTrafficStore`. A
 * single-tenant site leaves every row's `site_id` null and passes neither.
 *
 * CHANGED FROM GSC: the unique key is `(site_id, scan_id)`, not a bare
 * unique `scan_id` — gsc's original column computed `scan_id` from only the
 * date and the keyword (`'grid-'.$today->format('Ymd').'-'.substr(md5($keyword),0,8)`),
 * so two tenants scanning the same keyword on the same day would collide on
 * a shared table. A single-tenant site (null site_id on every row) sees no
 * behaviour change at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('map_pack_scans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->nullable()->index();
            $table->string('scan_id', 64);
            $table->string('keyword');
            $table->string('platform', 20)->nullable();
            $table->dateTime('scanned_at')->nullable();
            $table->decimal('arp', 6, 2)->nullable();   // average rank across grid points where found
            $table->decimal('atrp', 6, 2)->nullable();  // average rank, not-found points counted as 21st
            $table->decimal('solv', 6, 2)->nullable();  // share of local voice % — grid points in the 3-pack
            $table->decimal('saiv', 6, 2)->nullable();  // share of AI voice % — non-Maps platform rows only
            $table->unsignedSmallInteger('grid_points')->nullable();
            $table->unsignedSmallInteger('in_top3')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'scan_id']);
            $table->index(['site_id', 'keyword', 'scanned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('map_pack_scans');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The three tables `SsSystems\Platform\Seo\Intel\IntelStore` reads and
 * writes — copy this stub into your own database/migrations/ with today's
 * date (e.g. 2026_11_01_120000_create_seo_intel_tables.php). Every
 * DataForSEO "intelligence family" (SERP, Trends, on-page, backlinks, …) is
 * a scheduled `IntelSource` (`seo:intel {family}`) that, on its own cadence:
 *
 *   - seo_intel_snapshots: stores one row per family/kind/subject/day —
 *     what was measured (a flat metrics map) and the detail behind it
 *     (payload) — so the next run's comparison never needs the live API.
 *   - seo_intel_findings: opens a finding the first run that reports it
 *     (keyed by a stable fingerprint), keeps it open while every later run
 *     still reports it, resolves it the first run that stops.
 *   - seo_intel_runs: one row per run, so cost/duration/finding churn is
 *     visible without re-deriving it from the other two tables — the
 *     figure `seo:intel --budget` is guarding against.
 *
 * Ported from gs.construction's 2026_09_05_100000_create_seo_intel_tables
 * migration. One column dropped: `seo_intel_findings.seo_action_id`
 * (a foreign key into gs.construction's own autopilot-actions table) —
 * the kit ships no autopilot of its own, so a `Finding::$action` hint is
 * app data a site's own automation reads, never a kit-owned foreign key.
 *
 * MULTI-TENANT SITES: `site_id` is nullable/indexed on every table and part
 * of each unique key already — gs.construction passes `tenantColumn:
 * 'site_id'` plus a `tenantId` closure to `IntelStore` (same pattern as
 * `Seo\AiTraffic\AiTrafficStore`). A single-tenant site leaves every row's
 * `site_id` null and passes neither.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_intel_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->nullable()->index();
            $table->string('family', 40);
            $table->string('run_id', 40);
            $table->date('taken_on');
            $table->decimal('cost', 8, 4)->default(0);
            $table->unsignedInteger('snapshots')->default(0);
            $table->unsignedInteger('findings_open')->default(0);
            $table->unsignedInteger('findings_new')->default(0);
            $table->unsignedInteger('findings_resolved')->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->string('error', 500)->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'family', 'run_id']);
            $table->index(['site_id', 'family', 'taken_on']);
        });

        Schema::create('seo_intel_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->nullable()->index();
            $table->string('family', 40);
            $table->string('kind', 40);
            $table->string('subject', 191);
            $table->date('taken_on');
            $table->string('run_id', 40);
            $table->json('metrics')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'family', 'kind', 'subject', 'taken_on'], 'seo_intel_snapshots_unique');
        });

        Schema::create('seo_intel_findings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->nullable()->index();
            $table->string('family', 40);
            $table->string('code', 60);
            $table->string('severity', 10);
            $table->string('fingerprint', 40);
            $table->string('subject', 191)->nullable();
            $table->text('key')->nullable();
            $table->string('title', 255);
            $table->text('detail')->nullable();
            $table->json('delta')->nullable();
            $table->json('action')->nullable();
            $table->date('first_seen_on');
            $table->date('last_seen_on');
            $table->string('last_run_id', 40)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'fingerprint']);
            $table->index(['site_id', 'family', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_intel_findings');
        Schema::dropIfExists('seo_intel_snapshots');
        Schema::dropIfExists('seo_intel_runs');
    }
};

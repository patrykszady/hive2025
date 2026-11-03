<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The table `SsSystems\Platform\Seo\Clarity\ClarityMetricsStore` writes
 * (seo:clarity-sync) and `ClarityHealthReport` reads through the
 * `clarity_metrics` capability. Copy this stub into database/migrations/
 * with today's date. One site-wide row per Clarity project per day.
 *
 * Columns match gs.construction's clarity_daily_metrics (2026-05-19, plus
 * script_errors/error_clicks from 2026-06-16) and the copies
 * jpeterson-design and mozaika already made, with `site_id` nullable from
 * the start: single-tenant sites leave it null, a multi-tenant one binds
 * the store with a tenant column.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('clarity_daily_metrics')) {
            return;
        }

        Schema::create('clarity_daily_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->nullable()->index();
            $table->date('date');
            $table->string('project_id', 64);
            $table->unsignedInteger('sessions')->default(0);
            $table->unsignedInteger('users')->default(0);
            $table->unsignedInteger('pageviews')->default(0);
            $table->decimal('scroll_depth', 6, 2)->default(0);
            $table->unsignedInteger('active_time_seconds')->default(0);
            $table->decimal('bounce_rate', 6, 4)->default(0);
            $table->unsignedInteger('dead_clicks')->default(0);
            $table->unsignedInteger('rage_clicks')->default(0);
            $table->unsignedInteger('quickbacks')->default(0);
            $table->unsignedInteger('script_errors')->default(0);
            $table->unsignedInteger('error_clicks')->default(0);
            $table->timestamps();

            $table->unique(['site_id', 'project_id', 'date'], 'clarity_project_date_unique');
            $table->index(['date', 'project_id'], 'clarity_date_project_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clarity_daily_metrics');
    }
};

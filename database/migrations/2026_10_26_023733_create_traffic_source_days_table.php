<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use SsSystems\Platform\Traffic\TrafficSchema;

/**
 * Every provider's per-source numbers by day (kit 0.17): beacon visits,
 * Clarity sessions, Search Console and Bing search clicks, and the access
 * log's landings and crawls. This app is single-tenant (see site_events'
 * own migration), so no tenant column is added here either.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('traffic_source_days', fn (Blueprint $table) => TrafficSchema::createDailyTable($table));
    }

    public function down(): void
    {
        Schema::dropIfExists('traffic_source_days');
    }
};

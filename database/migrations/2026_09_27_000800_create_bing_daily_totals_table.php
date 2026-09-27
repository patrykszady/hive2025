<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * True site-wide Bing totals per day, from GetRankAndTrafficStats — these
 * include the clicks/impressions of queries Bing anonymises, which
 * bing_traffic_stats' per-query rows silently drop. Ported from
 * dawnsellshomes' identical table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bing_daily_totals', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('site_url', 191);
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->decimal('ctr', 8, 5)->default(0);
            $table->timestamps();

            $table->unique(['date', 'site_url'], 'bing_daily_totals_date_site_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bing_daily_totals');
    }
};

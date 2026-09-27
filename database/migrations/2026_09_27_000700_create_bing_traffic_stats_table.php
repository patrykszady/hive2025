<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bing Webmaster Tools query stats, one row per query per day — ported
 * from dawnsellshomes' identical table, written by seo:bing-sync through
 * the shared ss-systems/platform-kit BingSync and read by the SEO
 * snapshot's 'search' block's Bing channel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bing_traffic_stats', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('site_url', 191);
            $table->string('query', 500);
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->decimal('position', 6, 2)->default(0);
            $table->char('dim_hash', 40);
            $table->timestamps();

            $table->unique('dim_hash', 'bing_dim_hash_unique');
            $table->index(['site_url', 'date'], 'bing_traffic_site_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bing_traffic_stats');
    }
};

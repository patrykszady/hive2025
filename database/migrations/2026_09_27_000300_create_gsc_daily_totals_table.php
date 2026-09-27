<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * True site-wide GSC totals from a date-dimension-only query (includes
 * clicks/impressions from anonymized queries the query-dimension sync
 * drops). Ported from dawnsellshomes' identical table. This is the table
 * the SEO snapshot's trend/search blocks read from first, falling back to
 * gsc_query_metrics for a site synced before this table existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gsc_daily_totals', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('site_url', 191);
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->decimal('ctr', 8, 5)->default(0);
            $table->decimal('position', 8, 2)->default(0);
            $table->timestamps();

            $table->unique(['date', 'site_url'], 'gsc_daily_totals_date_site_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gsc_daily_totals');
    }
};

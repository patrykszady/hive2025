<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Weekly organic footprint + backlink profile, one row per domain per week
 * — copy this stub into your own database/migrations/ with today's date
 * (e.g. 2026_09_27_120000_create_seo_domain_overviews_table.php). Filled by
 * SsSystems\Platform\Seo\Visibility\Console\DomainOverviewCommand through
 * SsSystems\Platform\Seo\Visibility\DomainOverviewStore. Ported
 * byte-identical (columns only; the write path is now generic) from
 * gs.construction's `seo_domain_overviews`.
 *
 * Read by SsSystems\Platform\Seo\Visibility\VisibilitySnapshot::build() for
 * the "Search Visibility vs. Competitors" card's "Google footprint" table
 * (share_of_voice): keywords ranking top 1 / 2-3 / 4-10 / 11-20, estimated
 * traffic value (etv), and this run's keyword churn (is_new/is_lost) versus
 * the prior run for the same domain.
 *
 * `site_id` ships nullable + indexed from day one (unlike most of this
 * kit's other stubs, which comment it out) because gs.construction itself
 * is already multi-tenant for this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_domain_overviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->nullable()->index();
            $table->string('domain', 191);
            $table->boolean('is_us')->default(false);
            $table->date('date');
            $table->unsignedInteger('pos_1')->default(0);
            $table->unsignedInteger('pos_2_3')->default(0);
            $table->unsignedInteger('pos_4_10')->default(0);
            $table->unsignedInteger('pos_11_20')->default(0);
            $table->unsignedInteger('keywords_total')->default(0);
            $table->decimal('etv', 12, 2)->default(0);
            $table->unsignedInteger('is_new')->default(0);
            $table->unsignedInteger('is_lost')->default(0);
            $table->unsignedInteger('backlinks')->nullable();
            $table->unsignedInteger('referring_domains')->nullable();
            $table->unsignedInteger('domain_rank')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'domain', 'date'], 'seo_domain_overviews_site_domain_date_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_domain_overviews');
    }
};

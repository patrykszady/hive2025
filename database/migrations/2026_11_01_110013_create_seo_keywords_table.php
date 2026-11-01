<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The keyword universe: every phrase the site knows about (its own Search
 * Console queries, generated town x service phrases, competitors' ranked
 * keywords, keyword ideas) with its search volume, where the site stands and
 * where competitors stand. Copy this stub into your own
 * database/migrations/ with today's date (e.g.
 * 2026_11_01_120000_create_seo_keywords_table.php) — filled by
 * SsSystems\Platform\Seo\KeywordResearch\Console\KeywordResearchCommand
 * (`seo:keyword-research`), read by SsSystems\Platform\Seo\KeywordResearch\
 * KeywordResearchSnapshot for the "Keyword Opportunities" report card.
 *
 * Ported from gs.construction's seo_keywords (2026_09_04_210000 +
 * 2026_09_04_220000's `intent`/`intent_probability` alter), folded into one
 * create here. `intent_probability` is dropped: the kit's DataForSeoApi
 * contract's searchIntent() returns a plain label per keyword, not a
 * label+probability pair the way gs.construction's own DataForSeoService
 * did, so there is no probability to store.
 *
 * `site_id` is nullable and indexed, not a foreign key: a multi-tenant site
 * (gs.construction) passes its own tenant id through KeywordStore's
 * `$tenantColumn`/`$tenantId`; a single-tenant site leaves every row's
 * `site_id` null and both arguments unset.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_keywords', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('site_id')->nullable();
            $table->string('keyword', 191);
            $table->unsignedInteger('volume')->nullable();
            $table->decimal('cpc', 8, 2)->nullable();
            $table->decimal('competition', 4, 2)->nullable();
            $table->unsignedTinyInteger('difficulty')->nullable();
            $table->string('intent', 20)->nullable();
            $table->string('service', 40)->nullable();
            $table->string('city', 80)->nullable();
            $table->string('modifier', 30)->nullable();
            $table->json('sources')->nullable();
            $table->json('competitor_domains')->nullable();
            $table->unsignedSmallInteger('competitor_best_position')->nullable();
            $table->decimal('our_position', 6, 1)->nullable();
            $table->unsignedInteger('our_impressions')->nullable();
            $table->unsignedInteger('our_clicks')->nullable();
            $table->decimal('opportunity', 10, 2)->default(0);
            $table->timestamp('researched_at')->nullable();
            $table->timestamps();

            $table->index('site_id');
            $table->index('service');
            $table->index('city');
            $table->index('opportunity');
            $table->unique(['site_id', 'keyword']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_keywords');
    }
};

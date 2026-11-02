<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The table `SsSystems\Platform\Seo\IndexNow\IndexNowLedger` reads and
 * writes — copy this stub into your own database/migrations/ with today's
 * date (e.g. 2026_11_02_120000_create_indexnow_submissions_table.php). One
 * row per URL ever submitted to IndexNow (Bing/Yandex/Seznam/Naver): the
 * `lastmod` the sitemap reported at submission time, so the next hourly
 * `seo:indexnow` run only resends a URL that is new or has changed since —
 * `seo:indexnow --all` (the deploy line) bypasses this table entirely and
 * resubmits everything regardless of what is recorded here.
 *
 * Unifies jpeterson-design's `config/indexnow.php` + its key-file route +
 * `App\Jobs\SubmitUrlsToIndexNow` + `App\Support\IndexNowUrls`,
 * dawnsellshomes' `App\Console\Commands\SubmitSitemap` (`sitemap:submit`)
 * and ss-systems' `App\Console\Commands\IndexNowSubmit`
 * (`seo:indexnow-submit`) into the one shape every adopter starts from —
 * see `src/Seo/IndexNow/` for the client, crawler, command and this ledger.
 *
 * MULTI-TENANT SITES: `site_id` is nullable/indexed below and part of the
 * unique key — a multi-tenant site passes `tenantColumn: 'site_id'` plus a
 * `tenantId` closure to `IndexNowLedger`, exactly like `AiTrafficStore` and
 * `MapPackStore`. A single-tenant site leaves every row's `site_id` null
 * and passes neither.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('indexnow_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->nullable()->index();
            $table->string('url', 500);
            // ISO 8601, kept as the raw sitemap string rather than parsed —
            // IndexNowLedger::due() compares it with strtotime() at read
            // time, so no information is lost re-encoding it on the way in.
            $table->string('lastmod', 40)->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->string('last_status', 191)->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'url']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('indexnow_submissions');
    }
};

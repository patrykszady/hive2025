<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily clicks/impressions split by GSC searchAppearance (AI Overview,
 * review snippet, FAQ rich result, ...). The shared SearchConsoleSync
 * algorithm (ss-systems/platform-kit) always fetches and writes this
 * dimension as part of a normal sync run — Google 400s if it's ever asked
 * for alongside another dimension in one call, which is why this exists as
 * its own table rather than a column on gsc_query_metrics.
 *
 * Not surfaced in this site's seo/snapshot (no admin card reads it here) —
 * the table exists purely so App\Support\Seo\SearchConsoleWriter::
 * upsertSearchAppearance() has somewhere to write, keeping the kit's sync
 * algorithm intact rather than special-cased per site. Ported from
 * dawnsellshomes' identical table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gsc_search_appearance_metrics', function (Blueprint $table): void {
            $table->id();
            $table->date('date');
            $table->string('appearance', 64);
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->decimal('ctr', 8, 5)->default(0);
            $table->decimal('position', 6, 2)->default(0);
            $table->timestamps();
            $table->unique(['date', 'appearance'], 'gsc_search_appearance_date_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gsc_search_appearance_metrics');
    }
};

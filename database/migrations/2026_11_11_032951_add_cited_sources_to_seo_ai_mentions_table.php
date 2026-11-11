<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The web pages an AI answer engine cited while answering one of the GEO
 * questions: a JSON list, one entry per citation, written per answer by
 * the platform kit's `seo:ai-mentions` next to `businesses_named`, so the
 * "AI answers naming us" card can show which pages — this site's or a
 * competitor's — the engines lean on. The kit writes the column only when
 * it exists and nothing else reads it yet, so it ships nullable, and the
 * guards let this run on a database where the table was never created or
 * the column already was.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('seo_ai_mentions') && ! Schema::hasColumn('seo_ai_mentions', 'cited_sources')) {
            Schema::table('seo_ai_mentions', function (Blueprint $table) {
                $table->json('cited_sources')->nullable()->after('businesses_named');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('seo_ai_mentions') && Schema::hasColumn('seo_ai_mentions', 'cited_sources')) {
            Schema::table('seo_ai_mentions', function (Blueprint $table) {
                $table->dropColumn('cited_sources');
            });
        }
    }
};

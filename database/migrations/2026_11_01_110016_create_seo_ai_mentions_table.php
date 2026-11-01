<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GEO measurement: one row per (platform, town, service) question asked of
 * an AI answer engine (ChatGPT, Gemini, Perplexity, Claude), whether the
 * business was named, and where — copy this stub into your own
 * database/migrations/ with today's date (e.g.
 * 2026_09_27_120000_create_seo_ai_mentions_table.php). Filled by
 * SsSystems\Platform\Seo\Visibility\Console\AiMentionsCommand through
 * SsSystems\Platform\Seo\Visibility\AiMentionStore. Ported byte-identical
 * (columns only) from gs.construction's `seo_ai_mentions`.
 *
 * Read by SsSystems\Platform\Seo\Visibility\VisibilitySnapshot::build() for
 * the "AI answers naming us" half of the "Search Visibility vs.
 * Competitors" card: the mention rate on the latest asked_on day versus the
 * one before it, broken down by platform and by town, plus who the engines
 * name instead (businesses_named).
 *
 * `site_id` ships nullable + indexed from day one (unlike most of this
 * kit's other stubs, which comment it out) because gs.construction itself
 * is already multi-tenant for this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_ai_mentions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->nullable()->index();
            $table->string('platform', 30);
            $table->string('model', 60)->nullable();
            $table->string('prompt', 255);
            $table->string('town', 80)->nullable();
            $table->string('service', 40)->nullable();
            $table->boolean('mentioned')->default(false);
            $table->unsignedTinyInteger('mention_rank')->nullable();
            $table->json('businesses_named')->nullable();
            $table->text('answer_excerpt')->nullable();
            $table->date('asked_on');
            $table->timestamps();
            $table->index(['site_id', 'asked_on'], 'seo_ai_mentions_site_asked_on_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_ai_mentions');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Domains that link to the tracked competitors but not (yet) to us — the
 * monthly link-gap outreach list — copy this stub into your own
 * database/migrations/ with today's date (e.g.
 * 2026_09_27_120000_create_seo_backlink_prospects_table.php). Filled by
 * SsSystems\Platform\Seo\Visibility\Console\BacklinkGapCommand through
 * SsSystems\Platform\Seo\Visibility\BacklinkProspectStore. Ported
 * byte-identical (columns only) from gs.construction's
 * `seo_backlink_prospects` + its `spam_score` follow-up migration, folded
 * into one table here since this is a fresh stub for a new adopter.
 *
 * Read by SsSystems\Platform\Seo\Visibility\VisibilitySnapshot::build() for
 * the "Link gap" list: a prospect is shown when it links to 2-5 of the
 * tracked competitors, is not already linking to us, isn't flagged as spam
 * (spam_score >= 30), and isn't a free-host spam network or a directory —
 * link_gap_hidden counts how many prospects each reason hides.
 *
 * `site_id` ships nullable + indexed from day one (unlike most of this
 * kit's other stubs, which comment it out) because gs.construction itself
 * is already multi-tenant for this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_backlink_prospects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->nullable()->index();
            $table->string('domain', 191);
            $table->unsignedInteger('rank')->nullable();
            $table->json('links_to')->nullable();
            $table->unsignedTinyInteger('competitor_count')->default(0);
            $table->boolean('links_to_us')->default(false);
            $table->string('platform_type', 60)->nullable();
            $table->unsignedTinyInteger('spam_score')->nullable();
            $table->timestamp('seen_at')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'domain'], 'seo_backlink_prospects_site_domain_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_backlink_prospects');
    }
};

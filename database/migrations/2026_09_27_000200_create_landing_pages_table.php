<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Backs the ss.systems central admin's Landing Pages screen — hand-
     * created ad-campaign pages for Hive's own marketing site
     * ("Free Trial for Chicago Contractors", "Switch From Spreadsheets",
     * …), same contract as gs.construction's demand-driven `/remodeling/`
     * pages and dawnsellshomes.com's hand-created `/lp/` pages (see
     * App\Models\LandingPage and Api\Admin\V1\LandingPageController). Hive
     * has no Projects-proof domain of that kind at all, so there is no
     * proof_project_ids column and no proof gate — every row is hand-
     * created through the "Generate draft" form and, once published,
     * renders at `/lp/{slug}` ALWAYS noindex and never in the sitemap (see
     * LandingPage::shouldIndex() — these pages exist to receive paid
     * traffic, not to rank).
     */
    public function up(): void
    {
        Schema::create('landing_pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            // Campaign type slug (see LandingPage::CAMPAIGN_TYPES) — named
            // `service` to match gs.construction's/jpeterson-design's/
            // dawnsellshomes' admin API shape byte-for-byte (the same field
            // ss-systems' shared Livewire\Admin\LandingPages screen already
            // posts).
            $table->string('service');
            $table->string('city');
            // Free text (e.g. "Beta", "New") — no fixed dropdown of
            // remodeling-style modifiers makes sense for a SaaS campaign.
            $table->string('modifier')->nullable();
            $table->string('title');
            $table->string('h1');
            $table->string('meta_description')->nullable();
            $table->text('intro')->nullable();
            $table->json('sections')->nullable();
            $table->json('faq')->nullable();
            $table->string('status')->default('draft');
            $table->string('source')->default('manual');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landing_pages');
    }
};

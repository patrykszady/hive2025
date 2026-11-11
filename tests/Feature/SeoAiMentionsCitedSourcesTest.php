<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

/**
 * The platform kit's `seo:ai-mentions` stores the pages an AI engine cited
 * per answer in `seo_ai_mentions.cited_sources`, but only when that column
 * exists — a site whose migrations are behind silently keeps nothing. This
 * proves the column is there once the migrations have run.
 */
uses(RefreshDatabase::class);

it('has the cited_sources column on seo_ai_mentions after migrating', function () {
    expect(Schema::hasTable('seo_ai_mentions'))->toBeTrue()
        ->and(Schema::hasColumn('seo_ai_mentions', 'cited_sources'))->toBeTrue();
});

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bookkeeping for one run of a sync (Search Console, Bing), keyed by a
 * short slug ('search_console', 'bing'). Both ss-systems/platform-kit's
 * SearchConsoleWriter::recordSyncRun() and BingWriter contract expect the
 * site to persist a summary somewhere every run finishes — this site's
 * Search Console credential is a server-held service account, not a
 * per-owner OAuth grant, so there is no token row to piggyback the
 * bookkeeping on (same reasoning as dawnsellshomes, which this table is
 * ported from). One row per sync key, replaced whole on every run.
 *
 * Read by GET platforms/status to answer gsc.last_synced_at/
 * last_sync_status/last_sync_error/sync_stale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->string('sync_key')->unique();
            $table->json('summary')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_sync_runs');
    }
};

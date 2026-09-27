<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The JS Errors board (App\Http\Controllers\Api\Admin\V1\JsErrorController)
 * has no dedicated ingest table on this site — every occurrence is already
 * a `jserr` row in `site_events` (SsSystems\Platform\Pulse\Recorder, fired
 * by the beacon script's window.onerror / unhandledrejection listener, see
 * SsSystems\Platform\Pulse\BeaconScript::render()). App\Support\
 * JsErrorGroups groups those rows live by (kind, message, source:line) into
 * one aggregate per unique error; this table carries only the small bit of
 * state that grouping alone can't answer. Ported from dawnsellshomes.com's
 * 2026_09_26_090000_create_js_error_states_table (the working contract):
 *
 *   - `signature`  the group's stable sha256 key, so an id survives a
 *                   restart and matches the same error every time it recurs.
 *   - `resolved_at` when an operator marked the group resolved. Reopening
 *                   is never a write here — JsErrorGroups treats a group as
 *                   still-resolved only while resolved_at is at or after its
 *                   own last_seen_at; a fresh occurrence after that moment
 *                   naturally reads as reopened.
 *   - `deleted_before` the cutoff a "delete" sets (now(), at the moment of
 *                   deletion): JsErrorGroups ignores every occurrence at or
 *                   before it, so the group vanishes until (if ever) a new
 *                   occurrence lands after the cutoff.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('js_error_states', function (Blueprint $table) {
            $table->id();
            $table->string('signature', 64)->unique();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('deleted_before')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('js_error_states');
    }
};

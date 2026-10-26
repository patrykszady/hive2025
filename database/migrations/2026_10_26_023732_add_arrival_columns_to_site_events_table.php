<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use SsSystems\Platform\Traffic\TrafficSchema;

/**
 * Where each visit came from, on every beacon event (kit 0.17): the external
 * referrer, UTM tags, landing page, the named channel and source, and whether
 * the row is a visit's first page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_events', fn (Blueprint $table) => TrafficSchema::addArrivalColumns($table));
    }

    public function down(): void
    {
        Schema::table('site_events', fn (Blueprint $table) => TrafficSchema::dropArrivalColumns($table));
    }
};

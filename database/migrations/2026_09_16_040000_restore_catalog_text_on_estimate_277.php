<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * One-off: estimate 277 (Kitchen Remodel) was drafted by the model on
 * 2026-09-16 with its rewordings on every line. The catalog's description
 * and notes are what belong there (EstimateAIService now writes those);
 * this puts them back on the lines nobody has edited since. Runs with the
 * deploy; never fails it.
 */
return new class extends Migration
{
    public function up(): void
    {
        try {
            Artisan::call('estimates:restore-catalog-text', ['estimate' => 277]);
            Log::info('Migration: restored catalog text on estimate 277', ['output' => trim(Artisan::output())]);
        } catch (\Throwable $e) {
            Log::error('Migration: restoring catalog text on estimate 277 failed — run estimates:restore-catalog-text 277 by hand', ['error' => $e->getMessage()]);
        }
    }

    public function down(): void
    {
        // The model's rewordings are not something to put back.
    }
};

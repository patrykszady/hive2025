<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * One-time data fix riding the deploy: combine same-purchase receipt rows
 * (e-receipt + scan of the same purchase attached as full peers) that were
 * created before the supplement-precedence logic shipped. The command is
 * idempotent and non-destructive (demoted items are preserved in
 * supplanted_items, plus a JSON snapshot under storage/app/); running it
 * here means every environment is repaired exactly once, automatically.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('expense_receipts_data')) {
            return;
        }

        Artisan::call('receipts:combine-same-purchase');
    }

    public function down(): void
    {
        // Data fix — nothing to roll back (snapshots live in storage/app/).
    }
};

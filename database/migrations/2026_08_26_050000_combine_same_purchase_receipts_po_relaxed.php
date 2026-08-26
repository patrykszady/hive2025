<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * Second pass of the same-purchase combine, after relaxing the PO veto in
 * matchesSamePurchase: a purchase order present on only ONE capture no
 * longer blocks combining (scans routinely drop the PO the e-receipt
 * carries, and OCR misfiles payment lines into it). The first pass left
 * ~37 such pairs uncombined on production. Idempotent, snapshotting,
 * non-destructive — see receipts:combine-same-purchase.
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

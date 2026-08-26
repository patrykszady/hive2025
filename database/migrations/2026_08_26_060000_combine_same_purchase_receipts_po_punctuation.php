<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * Third pass of the same-purchase combine, after making the PO comparison
 * punctuation-blind: OCR renders the same purchase order as "329" on the
 * e-receipt and "(329" on its scan (expense 27160), which the previous
 * pass read as a conflict. Idempotent, snapshotting, non-destructive —
 * see receipts:combine-same-purchase.
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

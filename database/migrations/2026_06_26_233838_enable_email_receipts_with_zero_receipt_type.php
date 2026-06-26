<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `findMatchingReceipt()` only considers receipts with a non-zero
     * `receipt_type` (the model treats 0/null as "disabled"). Several email
     * receipt configs — e.g. BS&A (noreply@bsaonline.com / Village of
     * Northbrook) and InvoiceCloud (Village of Oak Park) — were saved with
     * `receipt_type = 0` despite having a real `from_address`, so every
     * incoming "Payment Confirmation" was silently skipped and dumped into the
     * NEED_TO_ADD folder.
     *
     * Any receipt that has a `from_address` is, by definition, an active email
     * receipt and should default to Purchase (1). Re-enable the misconfigured
     * ones here so matching picks them up.
     */
    public function up(): void
    {
        if (! Schema::hasTable('receipts')) {
            return;
        }

        DB::table('receipts')
            ->whereNotNull('from_address')
            ->where('from_address', '!=', '')
            ->where(function ($query) {
                $query->where('receipt_type', 0)
                    ->orWhereNull('receipt_type');
            })
            ->update(['receipt_type' => 1]);
    }

    /**
     * Not reversible: the original `receipt_type = 0` was a misconfiguration,
     * and restoring it would re-break receipt matching. Intentionally a no-op.
     */
    public function down(): void
    {
        //
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pivot tracking each "delivery" of an attachment within an auto-receipt
 * email batch. A single ExpenseReceipts row may be referenced from multiple
 * batches (when the same paper receipt is rescanned/forwarded) — the row is
 * stored once on the expense but appears in every batch's "recent auto
 * receipts" listing in the order it was uploaded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auto_receipt_email_batch_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')
                ->constrained('auto_receipt_email_batches')
                ->cascadeOnDelete();
            $table->unsignedInteger('expense_receipt_id');
            $table->foreign('expense_receipt_id')
                ->references('id')->on('expense_receipts_data')
                ->cascadeOnDelete();
            $table->unsignedInteger('attachment_index');
            $table->timestamps();

            $table->unique(['batch_id', 'attachment_index'], 'arebi_batch_attachment_unique');
            $table->index('expense_receipt_id', 'arebi_receipt_idx');
        });

        // Backfill from existing data: one item per (batch, attachment_index).
        // Where legacy data has multiple receipts for the same
        // (message_id, attachment_index) — i.e. accidental double-saves —
        // the earliest receipt id wins. The other rows stay attached to the
        // expense but drop out of the auto-receipts batch listing.
        $batchIdByMessageId = DB::table('auto_receipt_email_batches')
            ->pluck('id', 'message_id')
            ->all();

        if (empty($batchIdByMessageId)) {
            return;
        }

        $rows = DB::table('expense_receipts_data')
            ->select('id', 'auto_receipt_message_id', 'auto_receipt_attachment_index', 'created_at')
            ->whereNotNull('auto_receipt_message_id')
            ->whereNotNull('auto_receipt_attachment_index')
            ->orderBy('id')
            ->get();

        $now = now();
        $seen = [];
        foreach ($rows as $row) {
            $batchId = $batchIdByMessageId[$row->auto_receipt_message_id] ?? null;
            if ($batchId === null) {
                continue;
            }

            $key = $batchId . ':' . (int) $row->auto_receipt_attachment_index;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            DB::table('auto_receipt_email_batch_items')->insertOrIgnore([
                'batch_id' => $batchId,
                'expense_receipt_id' => $row->id,
                'attachment_index' => (int) $row->auto_receipt_attachment_index,
                'created_at' => $row->created_at ?? $now,
                'updated_at' => $row->created_at ?? $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_receipt_email_batch_items');
    }
};

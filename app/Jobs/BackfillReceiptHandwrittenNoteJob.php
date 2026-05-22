<?php

namespace App\Jobs;

use App\Http\Controllers\ReceiptController;
use App\Models\ExpenseReceipts;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class BackfillReceiptHandwrittenNoteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public int $receiptId,
        public bool $onlyNew = false,
    )
    {
        $this->onQueue('background');
    }

    public function handle(ReceiptController $receiptController): void
    {
        $receipt = ExpenseReceipts::query()->find($this->receiptId);
        if (! $receipt) {
            return;
        }

        $items = $receipt->receipt_items;
        if (! is_array($items)) {
            $items = (array) ($items ?? []);
        }

        $currentNotes = isset($items['handwritten_notes']) ? (array) $items['handwritten_notes'] : [];
        if ($this->onlyNew && ! empty($currentNotes)) {
            return;
        }

        $filename = trim((string) ($receipt->receipt_filename ?? ''));
        if ($filename === '') {
            return;
        }

        try {
            $result = $receiptController->extractReceipt('receipts/' . $filename, 'receipt');
        } catch (\Throwable $exception) {
            Log::warning('BackfillReceiptHandwrittenNoteJob extraction failed', [
                'receipt_id' => $receipt->id,
                'filename' => $filename,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        $newNotes = (array) ($result['fields']['handwritten_notes'] ?? []);

        $beforeJson = json_encode($currentNotes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $afterJson = json_encode($newNotes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($beforeJson === $afterJson) {
            return;
        }

        $items['handwritten_notes'] = $newNotes;
        $receipt->receipt_items = $items;
        $receipt->save();
    }
}

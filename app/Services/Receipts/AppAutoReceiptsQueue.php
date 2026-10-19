<?php

namespace App\Services\Receipts;

use App\Models\AutoReceiptEmailBatch;
use App\Models\Expense;
use App\Models\ExpenseReceipts;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;

/**
 * The app's read-only "Recent Auto Receipts" queue — mirrors
 * App\Livewire\Expenses\AutoReceipts' batches()/currentReceipt() exactly:
 * every auto-ingested receipt this vendor received, newest batch first,
 * each still carrying which numbered batch/delivery it arrived in (the same
 * receipt can appear more than once when a paper receipt was rescanned).
 *
 * Editing the underlying Expense (what the web does once a receipt opens)
 * stays on the app's existing Expense edit screen — this queue only reads
 * and links to it; it never writes. Capped to the newest 100 batches, a
 * bound the web page doesn't need (a desktop-only paginated prev/next),
 * because the app lists every receipt in that window in one response.
 */
class AppAutoReceiptsQueue
{
    private const MAX_BATCHES = 100;

    /**
     * @return list<array{id: int, batch_number: int, position_in_batch: int, received_at: ?string, image_url: ?string, merchant_name: ?string, expense: array{id: int, amount: float, date: ?string, vendor_name: ?string, project_name: ?string, web_path: string}}>
     */
    public function queue(?int $vendorId): array
    {
        $rows = [];

        foreach ($this->batches($vendorId) as $batchIndex => $batch) {
            foreach ($batch['receipts'] as $positionIndex => $receipt) {
                $expense = $receipt->expense;
                if (! $expense) {
                    continue;
                }

                $rows[] = [
                    'id' => $receipt->id,
                    'batch_number' => $batchIndex + 1,
                    'position_in_batch' => $positionIndex + 1,
                    'received_at' => $batch['received_at'],
                    'image_url' => $this->imageUrl($receipt),
                    'merchant_name' => $this->merchantName($receipt),
                    'expense' => [
                        'id' => $expense->id,
                        'amount' => (float) $expense->amount,
                        'date' => $expense->date?->format('Y-m-d'),
                        'vendor_name' => $expense->vendor?->name,
                        'project_name' => $expense->project?->project_name,
                        'web_path' => '/expenses/'.$expense->id,
                    ],
                ];
            }
        }

        return $rows;
    }

    /**
     * A single receipt's full detail — mirrors AutoReceipts::currentReceipt()
     * plus the OCR summary the web's receipt/OCR tab shows.
     *
     * @return array{id: int, image_url: ?string, merchant_name: ?string, purchase_order: ?string, invoice_number: ?string, line_items: list<array{description: ?string, product_code: ?string}>, expense: array{id: int, amount: float, date: ?string, note: ?string, vendor_name: ?string, project_name: ?string, web_path: string}}|null
     */
    public function detail(int $receiptId, ?int $vendorId): ?array
    {
        $receipt = ExpenseReceipts::with(['expense.vendor', 'expense.project'])->find($receiptId);

        if (! $receipt || ! $receipt->expense) {
            return null;
        }

        if ($vendorId !== null && $receipt->expense->belongs_to_vendor_id !== $vendorId) {
            return null;
        }

        $summary = $this->ocrSummary($receipt);
        $expense = $receipt->expense;

        return [
            'id' => $receipt->id,
            'image_url' => $this->imageUrl($receipt),
            'merchant_name' => $summary['merchant_name'],
            'purchase_order' => $summary['purchase_order'],
            'invoice_number' => $summary['invoice_number'],
            'line_items' => $summary['line_items'],
            'expense' => [
                'id' => $expense->id,
                'amount' => (float) $expense->amount,
                'date' => $expense->date?->format('Y-m-d'),
                'note' => $expense->note,
                'vendor_name' => $expense->vendor?->name,
                'project_name' => $expense->project?->project_name,
                'web_path' => '/expenses/'.$expense->id,
            ],
        ];
    }

    /**
     * @return list<array{received_at: ?string, receipts: Collection<int, ExpenseReceipts>}>
     */
    private function batches(?int $vendorId): array
    {
        $batches = AutoReceiptEmailBatch::query()
            ->when($vendorId, fn ($q) => $q->where('belongs_to_vendor_id', $vendorId))
            ->with(['items' => fn ($q) => $q->orderBy('attachment_index')->orderBy('id'), 'items.expenseReceipt.expense:id,belongs_to_vendor_id,amount,date,vendor_id,project_id,note'])
            ->orderByDesc('email_received_at')
            ->orderByDesc('id')
            ->limit(self::MAX_BATCHES)
            ->get();

        $out = [];

        foreach ($batches as $batch) {
            $receipts = $batch->items
                ->filter(fn ($item) => $item->expenseReceipt && $item->expenseReceipt->expense
                    && (! $vendorId || $item->expenseReceipt->expense->belongs_to_vendor_id === $vendorId))
                ->sortBy('attachment_index')
                ->map(fn ($item) => $item->expenseReceipt)
                ->values();

            if ($receipts->isNotEmpty()) {
                $out[] = ['received_at' => $batch->email_received_at?->toIso8601String(), 'receipts' => $receipts];
            }
        }

        return $out;
    }

    private function imageUrl(ExpenseReceipts $receipt): ?string
    {
        if (! $receipt->receipt_filename) {
            return null;
        }

        return URL::temporarySignedRoute('expenses.receipts.signed', now()->addHours(24), ['receipt' => $receipt->id]);
    }

    /**
     * @return array{merchant_name: ?string, purchase_order: ?string, invoice_number: ?string, line_items: list<array{description: ?string, product_code: ?string}>}
     */
    private function ocrSummary(ExpenseReceipts $receipt): array
    {
        $items = $receipt->receipt_items ?? [];
        $receiptObjects = (isset($items['items']) || isset($items['purchase_order'])) ? [$items] : (is_array($items) ? $items : []);

        $merchantName = null;
        $purchaseOrder = null;
        $invoiceNumber = null;
        $lineItems = [];

        foreach ($receiptObjects as $object) {
            if (! is_array($object)) {
                continue;
            }

            $merchantName ??= $object['merchant_name'] ?? null;
            $purchaseOrder ??= $object['purchase_order'] ?? null;
            $invoiceNumber ??= $object['invoice_number'] ?? null;

            foreach ($object['items'] ?? [] as $lineItem) {
                if (! is_array($lineItem)) {
                    continue;
                }
                $lineItems[] = [
                    'description' => $lineItem['Description'] ?? null,
                    'product_code' => $lineItem['ProductCode'] ?? null,
                ];
            }
        }

        return [
            'merchant_name' => $merchantName,
            'purchase_order' => $purchaseOrder,
            'invoice_number' => $invoiceNumber,
            'line_items' => $lineItems,
        ];
    }

    private function merchantName(ExpenseReceipts $receipt): ?string
    {
        return $this->ocrSummary($receipt)['merchant_name'];
    }
}

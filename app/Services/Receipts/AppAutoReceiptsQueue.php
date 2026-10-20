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
     * A single receipt's full detail, for the app's step-through reviewer —
     * mirrors AutoReceipts::currentReceipt() (the receipt/OCR tab) PLUS the
     * sibling receipts the web renders as "Receipt 1, Receipt 2…" sub-tabs
     * when the same Expense carries more than one (auto-receipts.blade.php's
     * `$expenseReceipts` collection), so the app can switch between them
     * without leaving this position. The matched Expense's full editable
     * detail is fetched separately, the same way the app's existing Expense
     * edit screen does (GET expenses/{expense}) — this stays a read of the
     * receipt scan/OCR only.
     *
     * @return array{id: int, image_url: ?string, is_material_order: bool, notes: ?string, merchant_name: ?string, purchase_order: ?string, invoice_number: ?string, total: ?float, items: list<mixed>, siblings: list<array{id: int, image_url: ?string, merchant_name: ?string, is_material_order: bool}>, expense: array{id: int, amount: float, date: ?string, note: ?string, vendor_name: ?string, project_name: ?string, web_path: string}}|null
     */
    public function detail(int $receiptId, ?int $vendorId): ?array
    {
        $receipt = ExpenseReceipts::with(['expense.vendor', 'expense.project', 'expense.receipts'])->find($receiptId);

        if (! $receipt || ! $receipt->expense) {
            return null;
        }

        if ($vendorId !== null && $receipt->expense->belongs_to_vendor_id !== $vendorId) {
            return null;
        }

        $expense = $receipt->expense;
        $items = $receipt->receipt_items ?? [];

        $siblings = $expense->receipts
            ->reject(fn (ExpenseReceipts $sibling) => (int) $sibling->id === (int) $receipt->id)
            ->sortByDesc('id')
            ->map(fn (ExpenseReceipts $sibling) => [
                'id' => $sibling->id,
                'image_url' => $this->imageUrl($sibling),
                'merchant_name' => $this->ocrSummary($sibling)['merchant_name'],
                'is_material_order' => (bool) $sibling->is_material_order,
            ])
            ->values()
            ->all();

        return [
            'id' => $receipt->id,
            'image_url' => $this->imageUrl($receipt),
            'is_material_order' => (bool) $receipt->is_material_order,
            'notes' => $receipt->notes,
            'merchant_name' => $items['merchant_name'] ?? null,
            'purchase_order' => $items['purchase_order'] ?? null,
            'invoice_number' => $items['invoice_number'] ?? null,
            'total' => isset($items['total']) ? (float) $items['total'] : null,
            'items' => $items['items'] ?? [],
            'siblings' => $siblings,
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

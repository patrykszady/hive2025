<?php

namespace App\Console\Commands;

use App\Models\ExpenseReceipts;
use App\Models\ReceiptLineItemDesc;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ReceiptSnapshotCommand extends Command
{
    protected $signature = 'receipts:snapshot
                            {--expense= : Expense ID to snapshot (required)}
                            {--compare : Diff current state against the most recent snapshot}';

    protected $description = 'Snapshot receipt item product URLs and image URLs to JSON for before/after comparison';

    public function handle(): int
    {
        $expenseId = $this->option('expense');
        if (! $expenseId) {
            $this->error('--expense is required.');
            return self::FAILURE;
        }

        $receipts = ExpenseReceipts::where('expense_id', $expenseId)->get();
        if ($receipts->isEmpty()) {
            $this->error("No receipts found for expense {$expenseId}.");
            return self::FAILURE;
        }

        $snapshot = $this->buildSnapshot((int) $expenseId, $receipts);

        if ($this->option('compare')) {
            return $this->compareWithLatest((int) $expenseId, $snapshot);
        }

        return $this->saveSnapshot((int) $expenseId, $snapshot);
    }

    /** @param \Illuminate\Database\Eloquent\Collection<int, ExpenseReceipts> $receipts */
    private function buildSnapshot(int $expenseId, $receipts): array
    {
        $data = [
            'expense_id'   => $expenseId,
            'generated_at' => now()->toDateTimeString(),
            'receipts'     => [],
        ];

        foreach ($receipts as $receipt) {
            $items = $receipt->receipt_items['items'] ?? [];
            $cacheRows = ReceiptLineItemDesc::where('expense_receipt_id', $receipt->id)
                ->get()
                ->keyBy('item_index');

            $snapshotItems = [];
            foreach ($items as $index => $item) {
                $cache = $cacheRows->get($index);
                $snapshotItems[] = [
                    'index'       => $index,
                    'mpn'         => $item['ManufacturerPartNumber'] ?? null,
                    'description' => $item['Description'] ?? null,
                    'manufacturer'=> $item['Manufacturer'] ?? null,
                    'product_url' => $item['product_url'] ?? null,
                    'image_url'   => $item['image_url'] ?? null,
                    'cache_product_url' => $cache?->product_url,
                    'cache_image_url'   => $cache?->product_image_url,
                ];
            }

            $data['receipts'][(string) $receipt->id] = [
                'receipt_id' => $receipt->id,
                'item_count' => count($snapshotItems),
                'items'      => $snapshotItems,
            ];
        }

        return $data;
    }

    private function saveSnapshot(int $expenseId, array $snapshot): int
    {
        $dir  = "receipt-snapshots/expense-{$expenseId}";
        $file = $dir . '/' . now()->format('Y-m-d_H-i-s') . '.json';

        Storage::disk('local')->makeDirectory($dir);
        Storage::disk('local')->put($file, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $path = storage_path("app/{$file}");
        $this->info("Snapshot saved: {$path}");

        $totalItems = collect($snapshot['receipts'])->sum('item_count');
        $withUrl    = collect($snapshot['receipts'])->flatMap(fn ($r) => $r['items'])->filter(fn ($i) => ! empty($i['product_url']))->count();
        $withImage  = collect($snapshot['receipts'])->flatMap(fn ($r) => $r['items'])->filter(fn ($i) => ! empty($i['image_url']))->count();

        $this->table(
            ['Receipts', 'Total Items', 'With product_url', 'With image_url'],
            [[count($snapshot['receipts']), $totalItems, $withUrl, $withImage]]
        );

        return self::SUCCESS;
    }

    private function compareWithLatest(int $expenseId, array $current): int
    {
        $dir   = "receipt-snapshots/expense-{$expenseId}";
        $files = collect(Storage::disk('local')->files($dir))
            ->filter(fn ($f) => str_ends_with($f, '.json'))
            ->sort()
            ->values();

        if ($files->isEmpty()) {
            $this->warn('No previous snapshot found. Saving current as first snapshot.');
            return $this->saveSnapshot($expenseId, $current);
        }

        $latestFile = $files->last();
        $previous   = json_decode(Storage::disk('local')->get($latestFile), true);

        $this->line('');
        $this->line("Comparing against: <comment>{$latestFile}</comment>");
        $this->line("Previous: <comment>{$previous['generated_at']}</comment>  →  Current: <comment>{$current['generated_at']}</comment>");
        $this->line('');

        $changes = [];

        foreach ($current['receipts'] as $receiptId => $receiptData) {
            $prevReceipt = $previous['receipts'][$receiptId] ?? null;
            $prevItems   = collect($prevReceipt['items'] ?? [])->keyBy('index');

            foreach ($receiptData['items'] as $item) {
                $prev = $prevItems->get($item['index']);

                $fields = ['product_url', 'image_url', 'cache_product_url', 'cache_image_url'];
                foreach ($fields as $field) {
                    $oldVal = $prev[$field] ?? null;
                    $newVal = $item[$field] ?? null;
                    if ($oldVal !== $newVal) {
                        $changes[] = [
                            'receipt'  => $receiptId,
                            'index'    => $item['index'],
                            'mpn'      => $item['mpn'] ?? '—',
                            'field'    => $field,
                            'old'      => $this->truncate($oldVal),
                            'new'      => $this->truncate($newVal),
                        ];
                    }
                }
            }
        }

        if (empty($changes)) {
            $this->info('No changes detected since last snapshot.');
        } else {
            $this->info(count($changes) . ' change(s) detected:');
            $this->table(['Receipt', 'Idx', 'MPN', 'Field', 'Old', 'New'], $changes);
        }

        // Save the current state as the new snapshot
        return $this->saveSnapshot($expenseId, $current);
    }

    private function truncate(?string $value, int $length = 60): string
    {
        if ($value === null) {
            return '—';
        }
        return strlen($value) > $length ? substr($value, 0, $length) . '…' : $value;
    }
}

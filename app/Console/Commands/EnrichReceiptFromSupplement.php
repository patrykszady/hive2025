<?php

namespace App\Console\Commands;

use App\Http\Controllers\ReceiptController;
use App\Jobs\ScrapeReceiptItemImagesV2;
use App\Models\ExpenseReceipts;
use App\Services\NylasService;
use App\Services\ReceiptItemEnricher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Enrich an existing ExpenseReceipts row by merging Manufacturer / MPN data
 * extracted from a "supplement" PDF (e.g. a vendor presentation/quote sheet)
 * delivered to the receipts@hive.contractors Test folder.
 *
 * Items are matched by Jaccard token similarity on Description, with a quantity
 * tie-breaker. Only NULL/empty Manufacturer and ManufacturerPartNumber fields
 * are filled; scraped image_url, product_url, VendorCode, Price, etc. are not
 * touched.
 */
class EnrichReceiptFromSupplement extends Command
{
    protected $signature = 'receipts:enrich-from-supplement
                            {--expense= : Expense ID whose ExpenseReceipts row should be enriched}
                            {--subject= : Substring of email subject to locate the supplement message in the Test folder}
                            {--folder=test : Nylas folder shortcut on the receipts grant (default: test)}
                            {--cache= : Optional path to a cached extractReceipt() JSON result (skips fetch + OCR)}
                            {--threshold=0.18 : Minimum Jaccard similarity to accept a description match}
                            {--rescrape : Queue ScrapeReceiptItemImagesV2 after enrichment}
                            {--dry-run : Show planned updates without saving}';

    protected $description = 'Merge Manufacturer + MPN data from a supplement PDF (in the receipts Test folder) into an ExpenseReceipts row.';

    public function handle(NylasService $nylasService, ReceiptController $receiptController, ReceiptItemEnricher $enricher): int
    {
        $expenseId = (int) $this->option('expense');
        if ($expenseId <= 0) {
            $this->error('--expense is required.');
            return self::FAILURE;
        }

        $receipt = ExpenseReceipts::where('expense_id', $expenseId)->first();
        if (! $receipt) {
            $this->error("No ExpenseReceipts row for expense {$expenseId}.");
            return self::FAILURE;
        }

        $existing = $receipt->receipt_items ?? [];
        $existingItems = $existing['items'] ?? [];
        if (empty($existingItems)) {
            $this->error("Receipt {$receipt->id} has no items to enrich.");
            return self::FAILURE;
        }

        $supplementItems = $this->loadSupplementItems($nylasService, $receiptController);
        if ($supplementItems === null) {
            return self::FAILURE;
        }

        $this->info("Receipt items: " . count($existingItems) . " | Supplement items: " . count($supplementItems));

        $updates = $enricher->planMerge($existingItems, $supplementItems, (float) $this->option('threshold'));

        $this->table(
            ['#', 'Sup#', 'Sim', 'Existing description', 'Mfg →', 'MPN →'],
            collect($updates)->map(fn ($u) => [
                $u['index'],
                $u['supplement_index'] ?? '-',
                $u['similarity'] !== null ? number_format($u['similarity'], 2) : '-',
                substr((string) $u['existing_description'], 0, 50),
                $u['new_manufacturer'] ?? '—',
                $u['new_mpn'] ?? '—',
            ])->all()
        );

        $changed = collect($updates)->filter(fn ($u) => $u['changed'])->count();
        $this->info("Would change {$changed} of " . count($existingItems) . " items.");

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        foreach ($updates as $u) {
            if (! $u['changed']) {
                continue;
            }
            if ($u['new_manufacturer'] !== null) {
                $existingItems[$u['index']]['Manufacturer'] = $u['new_manufacturer'];
            }
            if ($u['new_mpn'] !== null) {
                $existingItems[$u['index']]['ManufacturerPartNumber'] = $u['new_mpn'];
            }
        }
        $existing['items'] = $existingItems;
        $receipt->receipt_items = $existing;
        $receipt->save();

        $this->info("Saved ExpenseReceipts #{$receipt->id}.");

        if ($this->option('rescrape')) {
            ScrapeReceiptItemImagesV2::dispatch($receipt);
            $this->info("Queued ScrapeReceiptItemImagesV2 for receipt {$receipt->id}.");
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int,array<string,mixed>>|null
     */
    private function loadSupplementItems(NylasService $nylasService, ReceiptController $receiptController): ?array
    {
        $cachePath = $this->option('cache');
        if ($cachePath) {
            if (! is_file($cachePath)) {
                $this->error("Cache file not found: {$cachePath}");
                return null;
            }
            $decoded = json_decode((string) file_get_contents($cachePath), true);
            $items = $decoded['fields']['items'] ?? null;
            if (! is_array($items)) {
                $this->error("Cache file does not contain fields.items[].");
                return null;
            }
            $this->info("Loaded " . count($items) . " supplement items from cache: {$cachePath}");
            return $items;
        }

        $subject = (string) $this->option('subject');
        if ($subject === '') {
            $this->error('--subject (or --cache) is required.');
            return null;
        }

        $grantId = config('nylas.receipts_grant_id');
        $folder  = $this->option('folder');
        $folderId = match ($folder) {
            'test'  => config('nylas.hive_receipts_test_folder_id'),
            'saved' => config('nylas.hive_receipts_saved_folder_id'),
            'inbox' => null,
            default => $folder,
        };
        if (empty($folderId) && $folder !== 'inbox') {
            $this->error("Folder '{$folder}' is not configured.");
            return null;
        }

        $this->info("Searching folder '{$folder}' for subject containing \"{$subject}\"...");

        $messages = $nylasService->fetchFolderMessages($grantId, $folderId ?? 'INBOX', [
            'full_fetch'      => true,
            'include_headers' => false,
        ]);

        $matches = collect($messages)->filter(
            fn ($m) => stripos((string) ($m['subject'] ?? ''), $subject) !== false,
        )->values();

        if ($matches->isEmpty()) {
            $this->warn("No messages in folder '{$folder}' matched — falling back to grant-wide scan.");
            $page = $nylasService->getMessages($grantId, ['limit' => 200], false);
            $messages = $page['data'] ?? [];
            $matches = collect($messages)->filter(
                fn ($m) => stripos((string) ($m['subject'] ?? ''), $subject) !== false,
            )->values();
        }

        if ($matches->isEmpty()) {
            $this->error("No messages matched subject \"{$subject}\".");
            return null;
        }
        if ($matches->count() > 1) {
            $this->warn("Multiple messages match — using the most recent.");
            $matches = $matches->sortByDesc('date')->values();
        }

        $msg = $matches->first();
        $attachments = collect($msg['attachments'] ?? [])
            ->filter(function ($a) {
                $name = strtolower((string) ($a['filename'] ?? ''));
                $ct   = strtolower((string) ($a['content_type'] ?? ''));
                return str_ends_with($name, '.pdf')
                    || str_contains($ct, 'pdf')
                    || $ct === 'application/octet-stream';
            })
            ->values();

        if ($attachments->isEmpty()) {
            $this->error("Message has no PDF attachment.");
            return null;
        }

        $attachment = $attachments->first();
        $this->info("Downloading attachment: " . ($attachment['filename'] ?? 'unknown'));
        $binary = $nylasService->downloadAttachment(
            $attachment['id'],
            $grantId,
            $msg['id'],
        );

        $tempName = '_temp_ocr/supplement-' . date('Y-m-d-H-i-s') . '-' . random_int(10, 99) . '.pdf';
        Storage::disk('files')->put($tempName, $binary);

        try {
            $analyzerId = config('services.azure_cu.analyzer_id_material_order') ?: null;
            $this->line("Analyzer: " . ($analyzerId ?? '(default)'));
            $cu = app(\App\Services\ContentUnderstandingService::class);
            $rawAnalyze = $cu->analyze($tempName, 'material_order', 'nylas', $analyzerId);
        } catch (\Throwable $e) {
            $this->error('CU analyze threw: ' . $e->getMessage());
            return null;
        } finally {
            Storage::disk('files')->delete($tempName);
        }

        $rawItems = $rawAnalyze['analyzeResult']['documents'][0]['fields']['Items']['valueArray'] ?? [];
        if (empty($rawItems)) {
            $this->error('CU returned no Items in document.');
            return null;
        }

        $items = [];
        foreach ($rawItems as $row) {
            $obj = $row['valueObject'] ?? [];
            $desc   = $obj['Description']['valueString'] ?? null;
            $vendor = $obj['VendorCode']['valueString'] ?? $obj['ProductCode']['valueString'] ?? $obj['ItemNumber']['valueString'] ?? null;
            $mfr    = $obj['Manufacturer']['valueString'] ?? null;
            $mpn    = $obj['ManufacturerPartNumber']['valueString'] ?? null;
            $qtyRaw = $obj['Quantity']['valueString'] ?? $obj['Quantity']['content'] ?? (isset($obj['Quantity']['valueNumber']) ? (string) $obj['Quantity']['valueNumber'] : null);
            $qty    = is_numeric($qtyRaw) ? (float) $qtyRaw : 1;
            if (is_string($qtyRaw) && preg_match('/^([lI1-9]\d*)\s*([a-zA-Z]{2,3})$/i', trim($qtyRaw), $qm)) {
                $n = strtolower($qm[1]) === 'l' || strtolower($qm[1]) === 'i' ? '1' : $qm[1];
                $qty = (int) $n;
            }

            $items[] = [
                'Description'            => $desc,
                'VendorCode'             => $vendor,
                'Manufacturer'           => $mfr,
                'ManufacturerPartNumber' => $mpn,
                'Quantity'               => $qty,
            ];
        }

        $this->info("Parsed " . count($items) . " supplement items from CU.");
        return $items;
    }


}

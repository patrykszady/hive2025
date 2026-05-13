<?php

namespace App\Console\Commands;

use App\Http\Controllers\ReceiptController;
use App\Models\Expense;
use App\Models\ExpenseReceipts;
use App\Models\Receipt;
use App\Services\NylasService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Spatie\Browsershot\Browsershot;

class ReOcrReceipts extends Command
{
    protected $signature = 'receipts:re-ocr
                            {--receipt=18 : Receipt config ID to filter by (default: 18 = Home Depot)}
                            {--days=30 : Number of days to look back}
                            {--expense= : Re-OCR a single expense ID (skips email fetch, uses stored PDF)}
                            {--dry-run : Show what would be processed without making changes}';

    protected $description = 'Re-create PDFs from receipt emails in the Saved folder, re-run CU OCR, and update stored receipt data.';

    public function handle(NylasService $nylasService, ReceiptController $receiptController): int
    {
        // Single-expense mode: re-OCR the stored PDF directly
        if ($this->option('expense')) {
            return $this->reOcrSingleExpense($receiptController);
        }

        $dryRun  = (bool) $this->option('dry-run');
        $days    = (int) $this->option('days');
        $since   = now()->subDays($days);

        $receiptConfig = Receipt::find((int) $this->option('receipt'));
        if (! $receiptConfig) {
            $this->error('Receipt config not found.');
            return self::FAILURE;
        }

        $subjectsLabel = is_array($receiptConfig->from_subject)
            ? implode(' | ', $receiptConfig->from_subject)
            : (string) $receiptConfig->from_subject;
        $this->info("Receipt config: [{$receiptConfig->id}] {$receiptConfig->from_address} — \"{$subjectsLabel}\"");

        // Resolve the Saved folder ID
        $grantId     = config('nylas.receipts_grant_id');
        $savedFolder = config('nylas.hive_receipts_saved_folder_id');

        if (empty($savedFolder)) {
            $this->error('Saved folder ID not configured.');
            return self::FAILURE;
        }

        $this->info("Fetching messages from Saved folder (last {$days} days)...");

        $messages = $nylasService->fetchFolderMessages($grantId, $savedFolder, [
            'full_fetch'      => true,
            'received_after'  => $since,
            'include_headers' => true,
        ]);

        $this->info("Fetched " . count($messages) . " total messages from Saved folder.");

        // Filter to only messages matching this receipt config's from_address
        // Saved folder emails are forwarded — original sender is in X-Hive-Metadata header
        $fromAddress = strtolower($receiptConfig->from_address);
        $subjectMatches = $receiptConfig->from_subject ?? [];
        if (! is_array($subjectMatches)) {
            $subjectMatches = [(string) $subjectMatches];
        }
        $subjectMatches = array_values(array_filter(
            array_map('trim', $subjectMatches),
            fn ($v) => $v !== ''
        ));

        $filtered = collect($messages)->filter(function ($msg) use ($fromAddress, $subjectMatches) {
            // Parse X-Hive-Metadata header for original sender
            $metadata = $this->extractHiveMetadata($msg);
            $msgFrom = strtolower($metadata['from_email'] ?? ($msg['from'][0]['email'] ?? ''));
            $msgSubject = $metadata['subject'] ?? ($msg['subject'] ?? '');

            // Support wildcard from_address like "@stripe.com"
            if (str_starts_with($fromAddress, '@')) {
                $fromMatch = str_ends_with($msgFrom, $fromAddress);
            } else {
                $fromMatch = $msgFrom === $fromAddress;
            }

            if (! $fromMatch) {
                return false;
            }

            if (! empty($subjectMatches)) {
                $cleanSubject = preg_replace('/^(fw:|fwd:|re:)\s*/i', '', $msgSubject);
                foreach ($subjectMatches as $candidate) {
                    if (stripos($cleanSubject, $candidate) !== false) {
                        return true;
                    }
                }
                return false;
            }

            return true;
        });

        $this->info("Matched " . $filtered->count() . " messages for {$receiptConfig->from_address}.");

        if ($filtered->isEmpty()) {
            return self::SUCCESS;
        }

        $success = 0;
        $skipped = 0;
        $failed  = 0;

        foreach ($filtered as $message) {
            $messageId = $message['id'] ?? 'unknown';
            $subject   = $message['subject'] ?? '';
            $msgDate   = isset($message['date']) ? date('Y-m-d', $message['date']) : '?';

            $this->line("  [{$messageId}] {$msgDate} — {$subject}");

            if ($dryRun) {
                $success++;
                continue;
            }

            try {
                // Extract HTML body → PDF → OCR (same pipeline as fetchReceiptMessages)
                $result = $this->processEmailToOcr($message, $receiptConfig, $receiptController);

                if (! $result) {
                    $this->warn("    → Could not extract receipt HTML, skipping.");
                    $skipped++;
                    continue;
                }

                if (isset($result['error'])) {
                    $this->error("    → OCR returned error, skipping.");
                    $failed++;
                    continue;
                }

                // Match to existing expense by vendor + invoice + date
                $fields     = $result['fields'];
                $invoice    = $fields['invoice_number'] ?? null;
                $ocrDate    = $fields['transaction_date'] ?? $msgDate;
                $vendorId   = $receiptConfig->vendor_id;

                $baseQuery = fn () => Expense::where('vendor_id', $vendorId)
                    ->whereNull('deleted_at')
                    ->where('created_at', '>=', $since->copy()->subDays(5))
                    ->when($ocrDate && $ocrDate !== '?', fn ($q) => $q->whereBetween('date', [
                        \Carbon\Carbon::parse($ocrDate)->subDays(3)->format('Y-m-d'),
                        \Carbon\Carbon::parse($ocrDate)->addDays(3)->format('Y-m-d'),
                    ]));

                $expense = null;
                if ($invoice) {
                    $trimmedInvoice = trim($invoice);
                    // Try exact match first
                    $expense = $baseQuery()->where('invoice', $trimmedInvoice)->first();

                    // Fallback: stored invoice may contain or be contained by the OCR invoice
                    // e.g. OCR returns "1913 00062 49221" but DB has "49221", or vice versa
                    if (! $expense) {
                        $expense = $baseQuery()
                            ->where(function ($q) use ($trimmedInvoice) {
                                $q->where('invoice', 'LIKE', '%' . $trimmedInvoice . '%')
                                  ->orWhereRaw('? LIKE CONCAT(\'%\', invoice, \'%\')', [$trimmedInvoice]);
                            })
                            ->first();
                    }
                }

                if (! $expense) {
                    $this->warn("    → No matching expense found (invoice: {$invoice}, date: {$ocrDate}). Skipping.");
                    $skipped++;
                    continue;
                }

                // Find the ExpenseReceipts record to update
                $receiptRecord = ExpenseReceipts::where('expense_id', $expense->id)->first();
                if (! $receiptRecord) {
                    $this->warn("    → Expense {$expense->id} has no receipt record. Skipping.");
                    $skipped++;
                    continue;
                }

                // Update receipt data
                $data = $receiptRecord->receipt_items ?? [];
                $oldItems = $data['items'] ?? [];
                $data['items'] = $fields['items'];

                foreach (['subtotal', 'total', 'total_tax', 'taxes', 'tip', 'misc_fees', 'deposit', 'shipping', 'balance_due', 'transaction_date', 'merchant_name', 'invoice_number', 'purchase_order', 'handwritten_notes', 'payment_methods', 'raw_content'] as $key) {
                    if (array_key_exists($key, $fields)) {
                        $data[$key] = $fields[$key];
                    }
                }

                $receiptRecord->receipt_items = $data;
                $receiptRecord->receipt_html  = $result['content'] ?? $receiptRecord->receipt_html;
                $receiptRecord->save();

                $itemCount = is_array($fields['items']) ? count($fields['items']) : 0;
                $this->info("    → Expense {$expense->id}: {$itemCount} items (was " . count($oldItems) . ")");
                $success++;
            } catch (\Throwable $e) {
                $this->error("    → Failed: {$e->getMessage()}");
                Log::channel('nylas')->error('receipts:re-ocr failed', [
                    'message_id' => $messageId,
                    'error'      => $e->getMessage(),
                ]);
                $failed++;
            }
        }

        $this->newLine();
        $this->info("Done. Success: {$success}, Skipped: {$skipped}, Failed: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Extract receipt HTML from email body, render to PDF via Browsershot, and OCR.
     */
    private function processEmailToOcr(array $message, Receipt $receiptConfig, ReceiptController $receiptController): ?array
    {
        $body = $message['body'] ?? '';
        if (empty($body)) {
            return null;
        }

        $bodyType = strip_tags($body) !== $body ? 'html' : 'text';

        // Strip images
        $body = preg_replace("/<img[^>]+\>/i", '', $body);

        // Determine receipt start
        $receiptStart = 0;
        if (! empty($receiptConfig->options['receipt_start'])) {
            $starts = is_array($receiptConfig->options['receipt_start'])
                ? $receiptConfig->options['receipt_start']
                : [$receiptConfig->options['receipt_start']];

            $startOffset = isset($receiptConfig->options['receipt_start_offset'])
                ? intval($receiptConfig->options['receipt_start_offset'])
                : 0;

            foreach ($starts as $startText) {
                $pos = stripos($body, $startText);
                if ($pos !== false) {
                    $receiptStart = $pos + $startOffset + strlen($startText);
                    break;
                }
            }
        }

        // Determine receipt end
        $receiptEnd = strlen($body);
        if (! empty($receiptConfig->options['receipt_end'])) {
            $ends = is_array($receiptConfig->options['receipt_end'])
                ? $receiptConfig->options['receipt_end']
                : [$receiptConfig->options['receipt_end']];

            foreach ($ends as $endText) {
                $pos = strpos($body, $endText, $receiptStart);
                if ($pos !== false) {
                    $receiptEnd = $pos;
                    break;
                }
            }
        }

        $receiptHtml = substr($body, $receiptStart, $receiptEnd - $receiptStart);

        if (empty(trim(strip_tags($receiptHtml)))) {
            return null;
        }

        // Remove middle text if specified
        if (! empty($receiptConfig->options['receipt_middle_text'])) {
            preg_match($receiptConfig->options['receipt_middle_text'], $body, $matches);
            if (! empty($matches[1])) {
                $receiptHtml = str_replace($matches[1], '', $receiptHtml);
            }
        }

        // Remove embedded images
        if (! empty($receiptConfig->options['remove_images'])) {
            $receiptHtml = preg_replace('/<img\b[^>]*>/i', '', $receiptHtml) ?? $receiptHtml;
            $receiptHtml = preg_replace('/<v:imagedata\b[^>]*\/?>(?:<\/v:imagedata>)?/i', '', $receiptHtml) ?? $receiptHtml;
            $receiptHtml = preg_replace('/background-image\s*:\s*url\([^)]*\)\s*;?/i', '', $receiptHtml) ?? $receiptHtml;
        }

        // Strip HTML if configured
        if (! empty($receiptConfig->options['strip_html'])) {
            $receiptHtml = preg_replace('/<br\s*\/?>/i', "\n", $receiptHtml);
            $receiptHtml = preg_replace('/<\/(?:td|tr|p|div|li|h[1-6])>/i', "\n", $receiptHtml);
            $receiptHtml = strip_tags($receiptHtml);
            $receiptHtml = html_entity_decode($receiptHtml, ENT_QUOTES, 'UTF-8');
            $lines = array_map('trim', explode("\n", $receiptHtml));
            $lines = array_filter($lines, fn ($line) => $line !== '');
            $receiptHtml = implode("\n", $lines);
            $bodyType = 'text';
        }

        // Render to PDF via Browsershot
        $view = view('misc.create_pdf_receipt', [
            'receipt_html_main' => $receiptHtml,
            'message_type'      => $bodyType,
        ])->render();

        $tempFilename = 're-ocr-' . date('Y-m-d-H-i-s') . '-' . rand(10, 99) . '.pdf';
        $tempPath     = '_temp_ocr/' . $tempFilename;

        if (! Storage::disk('files')->exists('_temp_ocr')) {
            Storage::disk('files')->makeDirectory('_temp_ocr');
        }

        $location = Storage::disk('files')->path($tempPath);

        Browsershot::html($view)
            ->newHeadless()
            ->addChromiumArguments([
                'no-sandbox',
                'disable-setuid-sandbox',
                'disable-dev-shm-usage',
                'disable-gpu',
                'single-process',
            ])
            ->format('A4')
            ->margins(20, 0, 20, 20)
            ->save($location);

        // OCR the PDF
        try {
            $result = $receiptController->extractReceipt($tempPath, 'receipt', null, null, $receiptConfig);
        } finally {
            Storage::disk('files')->delete($tempPath);
        }

        return $result;
    }

    /**
     * Extract the original sender metadata from the X-Hive-Metadata header.
     */
    private function extractHiveMetadata(array $message): array
    {
        $headers = $message['headers'] ?? [];

        foreach ($headers as $header) {
            if (strcasecmp($header['name'] ?? '', 'X-Hive-Metadata') === 0) {
                $decoded = json_decode($header['value'] ?? '', true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        return [];
    }

    /**
     * Re-OCR a single expense using its stored PDF file.
     */
    private function reOcrSingleExpense(ReceiptController $receiptController): int
    {
        $records = ExpenseReceipts::where('expense_id', (int) $this->option('expense'))
            ->whereNotNull('receipt_filename')
            ->get();

        if ($records->isEmpty()) {
            $this->warn('No receipt record with a file found for expense ' . $this->option('expense') . '.');
            return self::SUCCESS;
        }

        $lastFields = null;
        foreach ($records as $record) {
            $result = $this->reOcrSingleReceipt($receiptController, $record);
            if ($result['status'] === 'failed') {
                return self::FAILURE;
            }
            if (isset($result['fields'])) {
                $lastFields = $result['fields'];
            }
        }

        // Sync parent expense fields (amount/date/invoice) from the last successfully
        // OCR'd receipt so re-OCR after a sign-flip / wrong-invoice bug fix actually
        // corrects the visible Expense Total — not just the receipt JSON.
        if ($lastFields !== null) {
            $this->syncExpenseFromFields((int) $this->option('expense'), $lastFields);
        }

        return self::SUCCESS;
    }

    /**
     * @return array{status: 'ok'|'skipped'|'failed', fields?: array<string, mixed>}
     */
    private function reOcrSingleReceipt(ReceiptController $receiptController, ExpenseReceipts $record): array
    {
        $filePath = 'receipts/' . $record->receipt_filename;
        if (! Storage::disk('files')->exists($filePath)) {
            $this->error("File not found: {$filePath}");
            return ['status' => 'failed'];
        }

        if ($this->option('dry-run')) {
            $this->line("Would re-OCR: {$record->receipt_filename}");
            return ['status' => 'skipped'];
        }

        $this->line("Re-OCR: {$record->receipt_filename}");

        // Use material order analyzer when the receipt is flagged as a material order
        $analyzerId = $record->is_material_order
            ? config('services.azure_cu.analyzer_id_material_order')
            : null;

        $docType = $record->is_material_order ? 'material_order' : 'receipt';

        $result = $receiptController->extractReceipt($filePath, $docType, null, null, null, $analyzerId);

        if (isset($result['error'])) {
            $this->error("OCR returned error.");
            return ['status' => 'failed'];
        }

        $fields   = $result['fields'];
        $data     = $record->receipt_items ?? [];
        $oldItems = $data['items'] ?? [];
        $newItems = $fields['items'] ?? [];

        // Preserve scraped image_url / product_url from old items. Match on
        // VendorCode first (most reliable), then fall back to Description
        // (so a transient analyzer regression that drops VendorCode for a
        // single line doesn't cost us the previously-resolved URL/image).
        $oldByCode = [];
        $oldByDesc = [];
        foreach ($oldItems as $old) {
            $hasMedia = ! empty($old['image_url']) || ! empty($old['product_url']);
            if (! $hasMedia) {
                continue;
            }
            $code = $old['VendorCode'] ?? $old['ProductCode'] ?? null;
            if ($code) {
                $oldByCode[$code] = $old;
            }
            $desc = trim((string) ($old['Description'] ?? ''));
            if ($desc !== '') {
                $oldByDesc[$desc] = $old;
            }
        }

        foreach ($newItems as &$newItem) {
            $code  = $newItem['VendorCode'] ?? null;
            $match = ($code && isset($oldByCode[$code])) ? $oldByCode[$code] : null;
            if (! $match) {
                $desc = trim((string) ($newItem['Description'] ?? ''));
                if ($desc !== '' && isset($oldByDesc[$desc])) {
                    $match = $oldByDesc[$desc];
                }
            }
            if ($match) {
                $newItem['image_url']   ??= $match['image_url']   ?? null;
                $newItem['product_url'] ??= $match['product_url'] ?? null;
            }
        }
        unset($newItem);

        $data['items'] = $newItems;

        foreach (['subtotal', 'total', 'total_tax', 'taxes', 'tip', 'misc_fees', 'deposit', 'shipping', 'balance_due', 'transaction_date', 'merchant_name', 'invoice_number', 'purchase_order', 'handwritten_notes', 'payment_methods', 'raw_content'] as $key) {
            if (array_key_exists($key, $fields)) {
                $data[$key] = $fields[$key];
            }
        }

        $record->receipt_items = $data;
        $record->receipt_html  = $result['content'] ?? $record->receipt_html;

        $record->save();

        $itemCount = is_array($fields['items']) ? count($fields['items']) : 0;
        $this->info("Updated: {$itemCount} items (was " . count($oldItems) . ")");

        return ['status' => 'ok', 'fields' => $fields];
    }

    /**
     * Sync parent expense fields (amount/date/invoice) so re-OCR after a sign-flip
     * bug fix actually corrects the visible Expense Total — not just the receipt JSON.
     *
     * @param  array<string, mixed>  $fields
     */
    private function syncExpenseFromFields(int $expenseId, array $fields): void
    {
        $expense = Expense::find($expenseId);
        if (! $expense) {
            return;
        }

        $changes = [];

        $newTotal = $fields['total'] ?? null;
        if (is_numeric($newTotal) && (float) $expense->amount !== (float) $newTotal) {
            $changes['amount'] = ['old' => $expense->amount, 'new' => (float) $newTotal];
            $expense->amount = (float) $newTotal;
        }

        $newDate = $fields['transaction_date'] ?? null;
        if (! empty($newDate)) {
            $oldDate = $expense->date instanceof \Carbon\Carbon
                ? $expense->date->format('Y-m-d')
                : substr((string) $expense->date, 0, 10);
            $newDateStr = substr((string) $newDate, 0, 10);
            if ($oldDate !== $newDateStr) {
                $changes['date'] = ['old' => $oldDate, 'new' => $newDateStr];
                $expense->date = $newDate;
            }
        }

        $newInvoice = $fields['invoice_number'] ?? null;
        if (! empty($newInvoice) && (string) $expense->invoice !== (string) $newInvoice) {
            $changes['invoice'] = ['old' => (string) $expense->invoice, 'new' => (string) $newInvoice];
            $expense->invoice = $newInvoice;
        }

        if (! empty($changes)) {
            $expense->save();
            foreach ($changes as $field => $diff) {
                $this->info("Expense {$expense->id} {$field}: {$diff['old']} → {$diff['new']}");
            }
        }
    }
}

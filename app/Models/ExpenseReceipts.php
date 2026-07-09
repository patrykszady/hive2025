<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class ExpenseReceipts extends Model
{
    use HasFactory, LogsActivity, Searchable, SoftDeletes;

    protected $table = 'expense_receipts_data';

    protected $fillable = [
        'expense_id',
        'receipt_filename',
        'receipt_html',
        'receipt_items',
        'is_material_order',
        'auto_receipt_message_id',
        'auto_receipt_attachment_index',
        'auto_receipt_email_received_at',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'receipt_items' => 'array',
        'is_material_order' => 'boolean',
        'auto_receipt_email_received_at' => 'datetime',
    ];

    public function searchableAs(): string
    {
        return app()->environment('local') ? 'expense_receipts_index_dev' : 'expense_receipts_index';
    }

    public function toSearchableArray(): array
    {
        $items = $this->receipt_items ?? [];

        $descriptions = [];
        $productCodes = [];
        $purchaseOrder = null;
        $invoiceNumber = null;
        $merchantName = null;

        // Handle both flat ({purchase_order: "..."}) and nested ([{items: [...], purchase_order: "..."}]) formats
        if (isset($items['items']) || isset($items['purchase_order'])) {
            // Single receipt object (flat)
            $this->extractFromReceiptObject($items, $descriptions, $productCodes, $purchaseOrder, $invoiceNumber, $merchantName);
        } else {
            // Array of receipt objects
            foreach ($items as $receipt) {
                if (is_array($receipt)) {
                    $this->extractFromReceiptObject($receipt, $descriptions, $productCodes, $purchaseOrder, $invoiceNumber, $merchantName);
                }
            }
        }

        return [
            'id' => $this->id,
            'expense_id' => (int) $this->expense_id,
            'belongs_to_vendor_id' => (int) ($this->expense?->belongs_to_vendor_id ?? 0),
            'descriptions' => implode(' | ', array_filter($descriptions)),
            'product_codes' => implode(' ', array_filter($productCodes)),
            'purchase_order' => $purchaseOrder,
            'invoice_number' => $invoiceNumber,
            'merchant_name' => $merchantName,
        ];
    }

    protected function makeAllSearchableUsing(Builder $query): Builder
    {
        return $query->with('expense:id,belongs_to_vendor_id');
    }

    /**
     * @param  array<string>  $descriptions
     * @param  array<string>  $productCodes
     */
    private function extractFromReceiptObject(array $receipt, array &$descriptions, array &$productCodes, ?string &$purchaseOrder, ?string &$invoiceNumber, ?string &$merchantName): void
    {
        $purchaseOrder ??= $receipt['purchase_order'] ?? null;
        $invoiceNumber ??= $receipt['invoice_number'] ?? null;
        $merchantName ??= $receipt['merchant_name'] ?? null;

        foreach ($receipt['items'] ?? [] as $lineItem) {
            if (! is_array($lineItem)) {
                continue;
            }
            if (! empty($lineItem['Description'])) {
                $descriptions[] = $lineItem['Description'];
            }
            if (! empty($lineItem['ProductCode'])) {
                $productCodes[] = $lineItem['ProductCode'];
            }
        }
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['receipt_items'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('materials');
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function lineItemDescs(): HasMany
    {
        return $this->hasMany(ReceiptLineItemDesc::class, 'expense_receipt_id');
    }

    public function autoReceiptBatchItems(): HasMany
    {
        return $this->hasMany(AutoReceiptEmailBatchItem::class, 'expense_receipt_id');
    }

    public static function isDuplicateForExpense(int $expenseId, ?string $receiptHtml, array $receiptItems): bool
    {
        return static::findDuplicateForExpense($expenseId, $receiptHtml, $receiptItems) !== null;
    }

    /**
     * Return the existing receipt that matches the given content for the
     * provided expense, or null if there's no duplicate.
     */
    public static function findDuplicateForExpense(int $expenseId, ?string $receiptHtml, array $receiptItems): ?self
    {
        $existingReceipts = static::query()
            ->where('expense_id', $expenseId)
            ->get(['id', 'receipt_html', 'receipt_items']);

        foreach ($existingReceipts as $existingReceipt) {
            if (static::matchesAsDuplicate(
                $existingReceipt->receipt_html,
                $existingReceipt->receipt_items ?? [],
                $receiptHtml,
                $receiptItems,
            )) {
                return $existingReceipt;
            }
        }

        return null;
    }

    /**
     * Pair-wise duplicate check between an "existing" receipt and a
     * "candidate" receipt's html + items payload. Extracted so that batch
     * cleanup commands can dedup within an expense without re-querying.
     *
     * Rules (any match → duplicate):
     *  1) Identical line-items hash.
     *  2) Identical normalized html hash.
     *  3) Subset rule: same total + transaction_date + normalized
     *     purchase_order, and the candidate's handwritten_notes are a subset
     *     of (or equal to) the existing receipt's notes.
     */
    public static function matchesAsDuplicate(
        ?string $existingHtml,
        array $existingItems,
        ?string $candidateHtml,
        array $candidateItems,
    ): bool {
        $existingLineItemsHash = static::receiptLineItemsHash($existingItems);
        $candidateLineItemsHash = static::receiptLineItemsHash($candidateItems);
        if ($existingLineItemsHash !== null && $candidateLineItemsHash !== null
            && hash_equals($existingLineItemsHash, $candidateLineItemsHash)
        ) {
            return true;
        }

        $existingHtmlHash = static::receiptHtmlHash($existingHtml);
        $candidateHtmlHash = static::receiptHtmlHash($candidateHtml);
        if ($existingHtmlHash !== '' && $candidateHtmlHash !== ''
            && hash_equals($existingHtmlHash, $candidateHtmlHash)
        ) {
            return true;
        }

        $candidateCoreSignature = static::normalizeReceiptCoreSignature($candidateItems);
        if ($candidateCoreSignature !== null) {
            $existingCoreSignature = static::normalizeReceiptCoreSignature($existingItems);
            if ($existingCoreSignature !== null
                && static::normalizePurchaseOrderForCompare($candidateItems)
                    === static::normalizePurchaseOrderForCompare($existingItems)
                && hash_equals($existingCoreSignature, $candidateCoreSignature)
            ) {
                $existingNotes = static::normalizeHandwrittenNotesForCompare($existingItems);
                $candidateNotes = static::normalizeHandwrittenNotesForCompare($candidateItems);
                if (($candidateNotes !== [] || $existingNotes !== [])
                    && static::isNotesSubset($candidateNotes, $existingNotes)
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Stable hash of just total + transaction_date — used by the subset rule
     * which evaluates handwritten_notes/purchase_order separately.
     */
    private static function normalizeReceiptCoreSignature(array $receiptItems): ?string
    {
        $total = $receiptItems['total'] ?? null;
        if ($total === null) {
            return null;
        }

        $core = [
            'transaction_date' => static::normalizeValue($receiptItems['transaction_date'] ?? null),
            'total' => static::normalizeValue($total),
        ];

        return sha1(json_encode($core, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array<int, string>
     */
    private static function normalizeHandwrittenNotesForCompare(array $receiptItems): array
    {
        $raw = $receiptItems['handwritten_notes'] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $notes = [];
        foreach ($raw as $n) {
            $n = trim((string) $n);
            if ($n !== '') {
                $notes[] = mb_strtolower($n);
            }
        }
        sort($notes);

        return array_values(array_unique($notes));
    }

    private static function normalizePurchaseOrderForCompare(array $receiptItems): string
    {
        $po = $receiptItems['purchase_order'] ?? null;
        $normalized = is_string($po) ? trim($po) : '';
        return $normalized === '0' ? '' : mb_strtolower($normalized);
    }

    /**
     * @param  array<int, string>  $needle
     * @param  array<int, string>  $haystack
     */
    private static function isNotesSubset(array $needle, array $haystack): bool
    {
        // Empty new notes against an existing receipt with notes is a subset
        // (the old one is strictly more informative).
        if ($needle === []) {
            return true;
        }

        foreach ($needle as $n) {
            if (! in_array($n, $haystack, true)) {
                return false;
            }
        }

        return true;
    }

    public static function receiptHtmlHash(?string $html): string
    {
        $normalized = static::normalizeReceiptHtml($html);
        return $normalized === '' ? '' : sha1($normalized);
    }

    public static function receiptLineItemsHash(?array $receiptItems): ?string
    {
        $signature = static::normalizeLineItemsSignature($receiptItems ?? []);
        if ($signature === []) {
            return null;
        }

        return sha1(json_encode($signature, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private static function normalizeReceiptHtml(?string $html): string
    {
        if ($html === null) {
            return '';
        }

        $html = str_replace(["\r\n", "\r"], "\n", $html);
        $html = trim($html);

        if ($html === '') {
            return '';
        }

        // Collapse whitespace and remove whitespace between tags.
        $html = preg_replace('/\s+/u', ' ', $html) ?? $html;
        $html = preg_replace('/>\s+</u', '><', $html) ?? $html;

        return trim($html);
    }

    /**
     * Build a stable signature based on receipt line items plus stable fields.
     * Includes: items, total, transaction_date, handwritten_notes, purchase_order.
     * Intentionally ignores OCR-variable fields like merchant_name and Description which can vary per extraction.
     */
    private static function normalizeLineItemsSignature(array $receiptItems): array
    {
        // Must have at least a total to generate a signature
        $total = $receiptItems['total'] ?? null;
        if ($total === null) {
            return [];
        }

        // Normalize purchase_order (string, can be empty or missing)
        // Treat "0" as empty since it's a placeholder value from Home Depot receipts
        $purchaseOrder = $receiptItems['purchase_order'] ?? null;
        $normalizedPo = is_string($purchaseOrder) ? trim($purchaseOrder) : '';
        if ($normalizedPo === '0') {
            $normalizedPo = '';
        }

        // Normalize handwritten_notes: array of strings → trimmed, lowercased,
        // sorted, joined. Two scans of the same paper with different handwritten
        // notes must NOT be considered duplicates (the user wants both attached
        // so they can see every note variant). Same paper scanned twice with the
        // same notes IS a duplicate. We accept the rare OCR-variation false
        // negative (e.g. "Kemida" vs "Kemide") in exchange for not silently
        // dropping legitimately-different scans.
        $rawNotes = $receiptItems['handwritten_notes'] ?? [];
        $notes = [];
        if (is_array($rawNotes)) {
            foreach ($rawNotes as $n) {
                $n = trim((string) $n);
                if ($n !== '') {
                    $notes[] = mb_strtolower($n);
                }
            }
            sort($notes);
        }

        $itemSignatures = [];
        foreach (($receiptItems['items'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $itemSignatures[] = [
                'vendor_code' => static::normalizeValue($item['VendorCode'] ?? $item['ProductCode'] ?? null),
                'mpn' => static::normalizeValue($item['ManufacturerPartNumber'] ?? null),
                'qty' => static::normalizeValue($item['Quantity'] ?? $item['quantity'] ?? null),
                'line_total' => static::normalizeValue($item['TotalPrice'] ?? $item['Amount'] ?? null),
            ];
        }

        usort($itemSignatures, static function (array $a, array $b): int {
            return strcmp(
                json_encode($a, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                json_encode($b, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            );
        });

        // Use total + transaction_date + purchase_order + handwritten_notes +
        // stable item signatures (qty/code/line_total). This still ignores
        // merchant_name and noisy OCR-only text while differentiating changed
        // line quantities.
        $signature = [
            'transaction_date' => static::normalizeValue($receiptItems['transaction_date'] ?? null),
            'total' => static::normalizeValue($total),
            'purchase_order' => $normalizedPo,
            'handwritten_notes' => $notes,
            'items' => $itemSignatures,
        ];

        return $signature;
    }

    private static function normalizeValue(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            // Stabilize float representation.
            return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return '';
            }

            if (is_numeric($trimmed)) {
                $floatVal = (float) $trimmed;
                return rtrim(rtrim(number_format($floatVal, 4, '.', ''), '0'), '.');
            }

            // Collapse whitespace.
            return preg_replace('/\s+/u', ' ', $trimmed) ?? $trimmed;
        }

        if (is_array($value)) {
            $isList = array_keys($value) === range(0, count($value) - 1);
            if ($isList) {
                $normalized = array_map(static fn ($v) => static::normalizeValue($v), $value);
                usort($normalized, static function (mixed $a, mixed $b): int {
                    return strcmp(json_encode($a, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), json_encode($b, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                });
                return $normalized;
            }

            $normalizedAssoc = [];
            foreach ($value as $k => $v) {
                $normalizedAssoc[(string) $k] = static::normalizeValue($v);
            }
            ksort($normalizedAssoc);
            return $normalizedAssoc;
        }

        if (is_object($value)) {
            $asArray = json_decode(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), true);
            return is_array($asArray) ? static::normalizeValue($asArray) : (string) $value;
        }

        return (string) $value;
    }

    /**
     * Strip a leading PO/job-number field label the OCR captured with the value,
     * e.g. "Job # or Name : 3143" → "3143", "PO #: 3143" → "3143". Leaves the
     * string untouched when it doesn't start with a recognized label.
     */
    public static function stripPoFieldLabel(string $note): string
    {
        $trimmed = trim($note);

        $pattern = '/^\s*(?:'
            . 'job\s*(?:#\s*)?(?:or\s*)?(?:name)?'
            . '|po\s*\/\s*job\s*name'
            . '|po\s*(?:number|no|#)?'
            . '|p\.?o\.?\s*#?'
            . '|pro\s*jobname'
            . ')\s*[:#-]\s*/i';

        $stripped = preg_replace($pattern, '', $trimmed);

        // Only accept the strip if it left a non-empty value.
        return is_string($stripped) && trim($stripped) !== '' ? trim($stripped) : $trimmed;
    }

    protected function notes(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                // Normalize handwritten_notes and purchase_order as arrays
                $handwritten_notes = isset($this->receipt_items['handwritten_notes'])
                    ? (array) $this->receipt_items['handwritten_notes']
                    : [];

                $purchase_order = isset($this->receipt_items['purchase_order'])
                    ? (array) $this->receipt_items['purchase_order']
                    : [];

                $hasMeaningfulPurchaseOrder = collect($purchase_order)
                    ->map(fn ($value) => is_string($value) ? trim($value) : '')
                    ->contains(fn ($value) => $value !== '' && $value !== '0');

                if (! $hasMeaningfulPurchaseOrder) {
                    $rawContent = (string) ($this->receipt_items['raw_content'] ?? '');
                    if ($rawContent !== '' && preg_match('/(?:PO\s*\/\s*JOB\s*NAME|PO\s*NUMBER|PO\s*#|P\.?O\.?\s*#?|JOB\s*NAME|PRO\s*JobName)\s*:\s*([^\r\n]{1,80})/i', $rawContent, $match)) {
                        $candidate = trim($match[1]);

                        // For tabular invoices, keep only the same-cell token and
                        // reject adjacent billing summary labels (e.g. "Discounts").
                        $candidate = trim(explode("\t", $candidate)[0] ?? '');
                        $candidate = rtrim($candidate, ':; ');

                        if (! preg_match('/^(discounts?|credits?|tax|subtotal|total|charges?|balance(?:\s+due)?|shipping|deposit|tip|fees?)$/i', $candidate)) {
                            $purchase_order = [$candidate];
                        }
                    }
                }

                // Combine, filter out empty/meaningless values, deduplicate
                $combined = array_merge($handwritten_notes, $purchase_order);
                $filtered = array_filter($combined, function ($note) {
                    if (! is_string($note)) {
                        return false;
                    }
                    $trimmed = trim($note);
                    // Filter out empty strings and meaningless placeholder values
                    return $trimmed !== '' && $trimmed !== '0';
                });

                // Strip leading receipt form-field labels that the OCR captured
                // alongside the value (e.g. "Job # or Name : 3143" → "3143").
                $filtered = array_map(fn ($note) => static::stripPoFieldLabel((string) $note), $filtered);

                $unique = array_values(array_unique(array_map('trim', $filtered)));

                // Containment dedup: drop a value that is already wholly contained
                // in another (case-insensitive). The purchase_order "3143" is
                // redundant when a handwritten note "Job # or Name : 3143" already
                // includes it — keep only the more informative note.
                $result = [];
                foreach ($unique as $note) {
                    $lower = mb_strtolower($note);
                    $isContained = false;
                    foreach ($unique as $other) {
                        if ($other === $note) {
                            continue;
                        }
                        $otherLower = mb_strtolower($other);
                        // $note is redundant if a longer sibling contains it.
                        if (mb_strlen($other) > mb_strlen($note) && str_contains($otherLower, $lower)) {
                            $isContained = true;
                            break;
                        }
                    }
                    if (! $isContained) {
                        $result[] = $note;
                    }
                }

                return implode(' | ', $result);
            }
        );
    }

    // Add this scope for ordering receipts
    public function scopeOrdered($query)
    {
        // First check if receipts have line items, then sort by ID (oldest first)
        // This ensures receipt 1 is the first receipt, not the newest
        $driver = $query->getConnection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            return $query->orderByRaw("
                CASE
                    WHEN JSON_CONTAINS_PATH(receipt_items, 'one', '$.items')
                    AND JSON_LENGTH(JSON_EXTRACT(receipt_items, '$.items')) > 0
                    THEN 1
                    ELSE 0
                END DESC
            ")->oldest('id');
        }

        // SQLite (and other drivers used in tests) — fall back to a portable check.
        return $query->orderByRaw("
            CASE
                WHEN receipt_items IS NOT NULL
                AND json_extract(receipt_items, '$.items') IS NOT NULL
                AND json_array_length(json_extract(receipt_items, '$.items')) > 0
                THEN 1
                ELSE 0
            END DESC
        ")->oldest('id');
    }

    // public function getReceiptItemsAttribute($value)
    // {
    //     if($value == NULL){
    //         $receipt_items = NULL;
    //     }else{
    //         $receipt_items = json_decode($value);
    //         if(!is_null($receipt_items->items)){
    //             foreach($receipt_items->items as $item){
    //                 if(isset($item->valueObject->Price->valueNumber)){
    //                     $item->price_each = $item->valueObject->Price->valueNumber;
    //                 }elseif(isset($item->valueObject->Price->valueCurrency)){
    //                     $item->price_each = $item->valueObject->Price->valueCurrency->amount;
    //                 }elseif(isset($item->valueObject->UnitPrice->valueCurrency)){
    //                     $item->price_each = $item->valueObject->UnitPrice->valueCurrency->amount;
    //                 }else{
    //                     $item->price_each = NULL;
    //                 }

    //                 if(isset($item->valueObject->TotalPrice->valueNumber)){
    //                     $item->price_total = $item->valueObject->TotalPrice->valueNumber;
    //                 }elseif(isset($item->valueObject->TotalPrice->valueCurrency)){
    //                     $item->price_total = $item->valueObject->TotalPrice->valueCurrency->amount;
    //                 }elseif(isset($item->valueObject->TotalPrice->valueNumber)){
    //                     $item->price_total = $item->valueObject->TotalPrice->valueNumber;
    //                 }elseif(isset($item->valueObject->Amount)){
    //                     $item->price_total = $item->valueObject->Amount->valueCurrency->amount;
    //                 }else{
    //                     $item->price_total = NULL;
    //                 }

    //                 if(isset($item->valueObject->Quantity->valueNumber)){
    //                     $item->quantity = $item->valueObject->Quantity->valueNumber;
    //                 }else{
    //                     if($item->price_each == $item->price_total){
    //                         $item->quantity = 1;
    //                     }else{
    //                         if(!is_null($item->price_total) && !is_null($item->price_each)){
    //                             $item->quantity = $item->price_total / $item->price_each;
    //                         }else{
    //                             $item->quantity = 1;
    //                         }
    //                     }
    //                 }

    //                 if(isset($item->valueObject->Description)){
    //                     $item->desc = $item->valueObject->Description->valueString;
    //                 }else{
    //                     $item->desc = $item->content;
    //                 }

    //                 $item->product_code = isset($item->valueObject->ProductCode->valueString) ? '# ' . $item->valueObject->ProductCode->valueString : NULL;
    //             }
    //         }
    //     }

    //     return $receipt_items;
    // }

    // public function getHandwrittenAttribute($value)
    // {
    //     $notes = $this->receipt_items->handwritten_notes;
    //     if($notes){
    //         return implode(", ", $notes);
    //     }else{
    //         return NULL;
    //     }
    // }

    // public function getReceiptDateAttribute($value)
    // {
    //     if(is_string($this->receipt_items->transaction_date)){
    //         $date = Carbon::parse($this->receipt_items->transaction_date);
    //     }else{
    //         $date = Carbon::parse($this->receipt_items->transaction_date->valueDate);
    //         // if(is_string($this->receipt_items->transaction_date)){
    //         //     $date = Carbon::parse($this->receipt_items->transaction_date->valueDate);
    //         // }else{
    //         //     $date = Carbon::parse($this->receipt_items->transaction_date->valueDate);
    //         // }
    //     }

    //     return $date;
    // }

    // public function getTaxAttribute($value)
    // {
    //     try {
    //         $this_subtotal = $this->subtotal;
    //     } catch (\Exception $e) {
    //         $this_subtotal = NULL;
    //     }

    //     if(is_string($this->receipt_items->total_tax) || is_float($this->receipt_items->total_tax)){
    //         $tax = $this->receipt_items->total_tax;
    //     }else{
    //         if(isset($this->receipt_items->total_tax->valueNumber)){
    //             $tax = $this->receipt_items->total_tax->valueNumber;
    //         }else{
    //             if(isset($this->total) && !is_null($this_subtotal)){
    //                 $tax = $this->total - $this->subtotal;
    //             }else{
    //                 $tax = FALSE;
    //             }
    //         }
    //     }

    //     return $tax;
    // }

    // public function getSubtotalAttribute($value)
    // {
    //     if($this->receipt_items->subtotal){
    //         // dd(is_numeric($this->receipt_items->subtotal));
    //         // if(is_string($this->receipt_items->subtotal) || is_float($this->receipt_items->subtotal)){
    //         if(is_numeric($this->receipt_items->subtotal)){
    //             $subtotal = $this->receipt_items->subtotal;
    //         }else{
    //             $subtotal = $this->receipt_items->subtotal->valueNumber;
    //         }
    //     }else{
    //         if(isset($this->total) && isset($this->tax)){
    //             $subtotal = $this->total - $this->tax;
    //         }else{
    //             $subtotal = FALSE;
    //         }
    //     }

    //     return $subtotal;
    // }

    // public function getTotalAttribute($value)
    // {
    //     if($this->expense->amount != $this->receipt_items->total){
    //         $total = $this->expense->amount;
    //     }else{
    //         $total = $this->receipt_items->total;
    //     }
    //     // if($this->receipt_items->total){
    //     //     $total = $this->receipt_items->total;
    //     // }else{
    //     //     $total = FALSE;
    //     // }

    //     return $total;
    // }
}

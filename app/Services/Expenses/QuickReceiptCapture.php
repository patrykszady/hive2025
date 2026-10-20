<?php

namespace App\Services\Expenses;

use App\Http\Controllers\CompanyEmailController;
use App\Http\Controllers\ReceiptController;
use App\Models\Expense;
use App\Models\Vendor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Reads a receipt photo via OCR for "Upload Receipt" (amount/date/vendor/
 * invoice off the receipt, no project chosen yet). The web's
 * App\Livewire\Expenses\ExpenseCreate::uploadReceipt() read()s it, parks it
 * with hold() and opens the new-expense form filled in — nothing is saved
 * until the user presses Create, which attach()es the receipt. The app's
 * quick capture (Api\App\V1\QuickReceiptController) still capture()s
 * straight into an Expense. Both produce the same Expense + OCR'd
 * ExpenseReceipts row, the way App\Services\Projects\ProjectMaterialsUploader
 * is shared by App\Livewire\Projects\ProjectMaterials and the app.
 */
class QuickReceiptCapture
{
    /** How long a receipt read by the web's "Upload Receipt" waits for its Create. */
    public const PENDING_TTL_HOURS = 24;

    /**
     * Reads the receipt into a brand-new Expense in one go — the app's
     * quick-capture endpoint, which then opens the expense for editing.
     *
     * @throws ValidationException on an invalid "belongs to vendor" id or a failed OCR read
     */
    public function capture(UploadedFile $file, bool $isMaterialOrder, ?int $belongsToVendorId, int $hubVendorId, int $actingUserId): Expense
    {
        $draft = $this->read($file, $isMaterialOrder, $belongsToVendorId, $hubVendorId);

        $expense = Expense::create([
            'amount' => $draft['fields']['amount'],
            'date' => $draft['fields']['date'],
            'invoice' => $draft['fields']['invoice'],
            'vendor_id' => $draft['fields']['vendor_id'],
            'belongs_to_vendor_id' => $draft['fields']['belongs_to_vendor_id'],
            'created_by_user_id' => $actingUserId,
        ]);

        $this->attach($expense, $draft);

        return $expense;
    }

    /**
     * Reads a receipt without creating anything: the expense fields it fills
     * (amount/date/vendor/invoice off the receipt) and the OCR result, with
     * the file kept in _temp_ocr until attach() files it under an expense.
     *
     * @return array{fields: array{amount: mixed, date: string, invoice: ?string, vendor_id: ?int, belongs_to_vendor_id: int, merchant_name: ?string}, ocr: array<string, mixed>, ocr_filename: string, is_material_order: bool}
     *
     * @throws ValidationException on an invalid "belongs to vendor" id or a failed OCR read
     */
    public function read(UploadedFile $file, bool $isMaterialOrder, ?int $belongsToVendorId, int $hubVendorId): array
    {
        // belongs_to_vendor_id is client-chosen — whitelist it to the hub
        // vendor or a vendor already linked to it (same set the "Belongs To
        // Vendor" dropdown offers), never an arbitrary vendor id. Checked up
        // front, before the OCR spend, so a tampered id fails fast.
        if ($isMaterialOrder && $belongsToVendorId && ! Vendor::whereKey($belongsToVendorId)->exists()) {
            throw ValidationException::withMessages([
                'belongs_to_vendor_id' => 'Invalid vendor selected for material order.',
            ]);
        }

        $docType = $file->getClientOriginalExtension();
        $ocrFilename = date('Y-m-d-H-i-s').'-'.rand(10, 99).'.'.$docType;
        $ocrPath = '_temp_ocr/'.$ocrFilename;

        Storage::disk('files')->put($ocrPath, file_get_contents($file->getRealPath()));

        $analyzerId = $isMaterialOrder ? config('services.azure_cu.analyzer_id_material_order') : null;
        $effectiveDocType = $isMaterialOrder ? 'material_order' : $docType;

        $ocrData = app(ReceiptController::class)
            ->extractReceipt($ocrPath, $effectiveDocType, null, null, null, $analyzerId);

        if (isset($ocrData['error']) && $ocrData['error'] === true) {
            Storage::disk('files')->delete($ocrPath);

            throw ValidationException::withMessages([
                'file' => 'Unable to process receipt. Please check the file and try again.',
            ]);
        }

        $fields = $ocrData['fields'] ?? [];

        // Match vendor from OCR merchant name.
        $vendorId = null;
        $merchantName = $fields['merchant_name'] ?? null;
        if ($merchantName) {
            $vendors = Vendor::orderBy('business_name')->get(['id', 'business_name']);
            $matched = app(CompanyEmailController::class)->fuzzyMatchVendor($merchantName, $vendors, 70.0);
            if ($matched) {
                $vendorId = $matched->id;
            }
        }

        return [
            'fields' => [
                'amount' => $fields['total'] ?? 0,
                'date' => $fields['transaction_date'] ?? now()->format('Y-m-d'),
                'invoice' => $fields['invoice_number'] ?? null,
                'vendor_id' => $vendorId,
                'belongs_to_vendor_id' => ($isMaterialOrder && $belongsToVendorId) ? $belongsToVendorId : $hubVendorId,
                'merchant_name' => is_string($merchantName) ? $merchantName : null,
            ],
            'ocr' => $ocrData,
            'ocr_filename' => $ocrFilename,
            'is_material_order' => $isMaterialOrder,
        ];
    }

    /**
     * Files a read receipt under its expense (the OCR'd ExpenseReceipts row)
     * and drops the temp file.
     *
     * @param  array{ocr: array<string, mixed>, ocr_filename: string, is_material_order: bool}  $draft
     */
    public function attach(Expense $expense, array $draft): void
    {
        app(CompanyEmailController::class)
            ->saveExpenseReceipt($expense->id, $draft['ocr'], $draft['ocr_filename'], null, false, $draft['is_material_order']);

        $this->discard($draft);
    }

    /** @param  array{ocr_filename: string}  $draft */
    public function discard(array $draft): void
    {
        Storage::disk('files')->delete('_temp_ocr/'.$draft['ocr_filename']);
    }

    /**
     * Parks a read receipt until the user presses Create on the new-expense
     * form. The token lives in that form, so it is scoped to the user who
     * uploaded the receipt: another user's token reads nothing.
     *
     * @param  array<string, mixed>  $draft
     */
    public function hold(array $draft, int $userId): string
    {
        $token = Str::random(40);

        Cache::put($this->pendingKey($token, $userId), $draft, now()->addHours(self::PENDING_TTL_HOURS));

        return $token;
    }

    /** @return array<string, mixed>|null the parked receipt, removed from the cache */
    public function release(string $token, int $userId): ?array
    {
        $draft = Cache::pull($this->pendingKey($token, $userId));

        return is_array($draft) ? $draft : null;
    }

    /** Drops a parked receipt the user walked away from, temp file and all. */
    public function forget(string $token, int $userId): void
    {
        $draft = $this->release($token, $userId);

        if ($draft) {
            $this->discard($draft);
        }
    }

    private function pendingKey(string $token, int $userId): string
    {
        return "expense-receipt-pending:{$userId}:{$token}";
    }
}

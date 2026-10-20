<?php

namespace App\Services\Expenses;

use App\Http\Controllers\CompanyEmailController;
use App\Http\Controllers\ReceiptController;
use App\Models\Expense;
use App\Models\Vendor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Captures a receipt photo straight into a brand-new Expense via OCR —
 * exactly App\Livewire\Expenses\ExpenseCreate::uploadReceipt()'s "Upload
 * Receipt" quick-capture flow (amount/date/vendor/invoice read off the
 * receipt, no project chosen yet; the user fills in the rest on the edit
 * form that opens next). ExpenseCreate now delegates to this, same as
 * App\Services\Projects\ProjectMaterialsUploader does for
 * App\Livewire\Projects\ProjectMaterials — shared with the Hive Contractors
 * app's own quick-capture endpoint (Api\App\V1\QuickReceiptController) so
 * both produce the same Expense + OCR'd ExpenseReceipts row.
 */
class QuickReceiptCapture
{
    /** @throws ValidationException on an invalid "belongs to vendor" id or a failed OCR read */
    public function capture(UploadedFile $file, bool $isMaterialOrder, ?int $belongsToVendorId, int $hubVendorId, int $actingUserId): Expense
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

        $ownerVendorId = ($isMaterialOrder && $belongsToVendorId) ? $belongsToVendorId : $hubVendorId;

        $expense = Expense::create([
            'amount' => $fields['total'] ?? 0,
            'date' => $fields['transaction_date'] ?? now()->format('Y-m-d'),
            'invoice' => $fields['invoice_number'] ?? null,
            'vendor_id' => $vendorId,
            'belongs_to_vendor_id' => $ownerVendorId,
            'created_by_user_id' => $actingUserId,
        ]);

        // Save the receipt data.
        app(CompanyEmailController::class)
            ->saveExpenseReceipt($expense->id, $ocrData, $ocrFilename, null, false, $isMaterialOrder);

        // Clean up temp file.
        Storage::disk('files')->delete($ocrPath);

        return $expense;
    }
}

<?php

namespace App\Services\Projects;

use App\Http\Controllers\CompanyEmailController;
use App\Http\Controllers\ReceiptController;
use App\Models\Expense;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\Vendor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Uploads and OCRs a materials-order receipt exactly like
 * App\Livewire\Projects\ProjectMaterials::uploadReceipt() — shared by that
 * component and the Hive Contractors app's project page so both run the
 * same Content Understanding analysis, vendor fuzzy-match, Expense creation
 * and project-vendor attach.
 */
class ProjectMaterialsUploader
{
    /** @throws ValidationException */
    public function upload(Project $project, UploadedFile $file, ?int $belongsToVendorId, int $actingUserId, int $hubVendorId): Expense
    {
        // belongs_to_vendor_id is client-chosen — whitelist it to the hub
        // vendor or a vendor already linked to it (same set the web's
        // "Belongs To Vendor" dropdown offers), never an arbitrary vendor id.
        if ($belongsToVendorId && ! Vendor::whereKey($belongsToVendorId)->exists()) {
            throw ValidationException::withMessages([
                'belongs_to_vendor_id' => 'Invalid vendor selected.',
            ]);
        }

        $docType = $file->getClientOriginalExtension();
        $ocrFilename = date('Y-m-d-H-i-s').'-'.rand(10, 99).'.'.$docType;
        $ocrPath = '_temp_ocr/'.$ocrFilename;

        Storage::disk('files')->put($ocrPath, file_get_contents($file->getRealPath()));

        $analyzerId = config('services.azure_cu.analyzer_id_material_order');

        $ocrData = app(ReceiptController::class)
            ->extractReceipt($ocrPath, 'material_order', null, null, null, $analyzerId);

        if (isset($ocrData['error']) && $ocrData['error'] === true) {
            Storage::disk('files')->delete($ocrPath);

            throw ValidationException::withMessages([
                'file' => 'Unable to process receipt. Please check the file and try again.',
            ]);
        }

        $fields = $ocrData['fields'] ?? [];

        // Match vendor from OCR merchant name
        $vendorId = null;
        $merchantName = $fields['merchant_name'] ?? null;

        if ($merchantName) {
            $vendors = Vendor::orderBy('business_name')->get(['id', 'business_name']);
            $matched = app(CompanyEmailController::class)->fuzzyMatchVendor($merchantName, $vendors, 70.0);

            if ($matched) {
                $vendorId = $matched->id;
            }
        }

        $ownerVendorId = $belongsToVendorId ?: $hubVendorId;

        $expense = Expense::create([
            'amount' => $fields['total'] ?? 0,
            'date' => $fields['transaction_date'] ?? now()->format('Y-m-d'),
            'invoice' => $fields['invoice_number'] ?? null,
            'vendor_id' => $vendorId,
            'project_id' => $project->id,
            'belongs_to_vendor_id' => $ownerVendorId,
            'created_by_user_id' => $actingUserId,
        ]);

        // Ensure the matched vendor is in the owner vendor's vendor list.
        // Scoped: $ownerVendorId was checked above against the hub vendor or
        // a vendor already linked to it.
        if ($vendorId && $ownerVendorId !== $hubVendorId) {
            $ownerVendor = Vendor::find($ownerVendorId);
            if ($ownerVendor && ! $ownerVendor->vendors()->where('vendor_id', $vendorId)->exists()) {
                $ownerVendor->vendors()->attach($vendorId);
            }
        }

        // Attach project to the owner vendor with Invited status
        if ($ownerVendorId !== $hubVendorId) {
            if (! $project->vendors()->withoutGlobalScopes()->where('vendor_id', $ownerVendorId)->exists()) {
                $project->vendors()->withoutGlobalScopes()->attach($ownerVendorId, ['client_id' => $project->client_id]);

                if ($project->client_id && ! DB::table('client_vendor')->where('client_id', $project->client_id)->where('vendor_id', $ownerVendorId)->exists()) {
                    DB::table('client_vendor')->insert([
                        'client_id' => $project->client_id,
                        'vendor_id' => $ownerVendorId,
                        'source' => 'auto-attach',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                ProjectStatus::create([
                    'project_id' => $project->id,
                    'belongs_to_vendor_id' => $ownerVendorId,
                    'status_code' => ProjectStatus::getCodeForLabel('Invited') ?? 1,
                    'start_date' => today()->format('Y-m-d'),
                ]);
            }
        }

        app(CompanyEmailController::class)
            ->saveExpenseReceipt($expense->id, $ocrData, $ocrFilename, null, false, true);

        Storage::disk('files')->delete($ocrPath);

        return $expense;
    }
}

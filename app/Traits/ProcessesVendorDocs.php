<?php

namespace App\Traits;

use App\Models\Agent;
use App\Models\Vendor;
use App\Models\VendorDoc;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

use Log;

trait ProcessesVendorDocs
{
    public function handleVendorDocProcessing(
        $filePath,
        $docType,
        $vendorId = null,
        $belongsToVendorId = null,
        $messageId = null,
        $grantId = null
    ) {
        // 1. Extract data via OCR
        $insuranceInfo = $this->extractDataFromFile($filePath, $docType);

        // 2. Always run matchBusinessNameAndReturnId to calculate IDs
        $calculatedVendorId = $this->matchBusinessNameAndReturnId($insuranceInfo['insured_name']['valueString']);
        $calculatedBelongsToVendorId = $this->matchBusinessNameAndReturnId($insuranceInfo['holder_name']['valueString']);

        // 3. If explicit IDs are provided, log a warning if they don't match the calculated ones
        if ($vendorId) {
            if ($vendorId != $calculatedVendorId) {
                Log::warning("VendorDocCreate: Provided vendorId ($vendorId) does not match calculated vendorId ($calculatedVendorId).");
            }
        }
        if ($belongsToVendorId) {
            if ($belongsToVendorId != $calculatedBelongsToVendorId) {
                Log::warning("VendorDocCreate: Provided belongsToVendorId ($belongsToVendorId) does not match calculated vendorId ($calculatedBelongsToVendorId).");
            }
        }

        // 4. Decide which values to use.
        $matchedVendorId = $calculatedVendorId ?: $vendorId;
        $matchedBelongsToVendorId = $calculatedBelongsToVendorId ?: $belongsToVendorId;

        // 5. For email-based processing, move the email based on matching results.
        if (isset($messageId) && isset($grantId)) {
            $this->moveEmailBasedOnMatchingResults(
                $messageId,
                $grantId,
                $matchedVendorId,
                $matchedBelongsToVendorId
            );
        }

        // 6. If either vendor ID is still missing, stop further processing.
        if (is_null($matchedVendorId) || is_null($matchedBelongsToVendorId)) {
            return false;
        }

        // 7. Process and/or create the Agent record if OCR returned agent details.
        $agent = null;
        if (
            isset($insuranceInfo['agent_email']['valueString']) &&
            !empty($insuranceInfo['agent_email']['valueString'])
        ) {
            $agent = Agent::firstOrCreate(
                ['email' => $insuranceInfo['agent_email']['valueString']],
                [
                    'name'          => $insuranceInfo['agent_name']['valueString'] ?? null,
                    'business_name' => $insuranceInfo['agent_agency']['valueString'] ?? null,
                    'address'       => $insuranceInfo['agent_agency_address']['valueString'] ?? null,
                    'phone'         => $insuranceInfo['agent_phone']['content'] ?? null,
                ]
            );
        }

        // 8. Create a single permanent file name and new storage path.
        $fileName = "{$matchedBelongsToVendorId}-{$matchedVendorId}-" . now()->format('Y-m-d-H-i-s') . ".{$docType}";
        $newFilePath = "vendor_docs/{$fileName}";

        // Move from temporary storage (temp_vendor_docs) to permanent storage.
        Storage::disk('files')->move($filePath, $newFilePath);

        // 9. Process both policy types in a single loop.
        $policyTypes = [
            ['policyKey' => 'general_multi', 'type' => 'general'],
            ['policyKey' => 'workers_multi', 'type' => 'workers'],
        ];

        $newPolicyCreated = false;
        foreach ($policyTypes as $policy) {
            $result = $this->processPolicies(
                $insuranceInfo,
                $policy['policyKey'],
                $policy['type'],
                $newFilePath,   // Permanent file location (moved file)
                $fileName,      // The file name to save in the database
                $matchedVendorId,
                $matchedBelongsToVendorId,
                $agent          // Pass the agent (if any) for association
            );
            if ($result === true) {
                $newPolicyCreated = true;
            }
        }

        // 10. If no new vendorDoc was created (i.e. duplicate), delete the permanent file.
        if (!$newPolicyCreated) {
            Storage::disk('files')->delete($newFilePath);
        }
    }

    private function extractDataFromFile($filePath, $docType)
    {
        // Perform OCR and return extracted data
        //4/7/2025 MOVE TO A SERVICE?
        return app(\App\Http\Controllers\ReceiptController::class)->azure_docs_api($filePath, $docType)['analyzeResult']['documents'][0]['fields'];
    }

    private function processPolicies(
        $insuranceInfo,
        $policyKey,
        $type,
        $newFilePath,
        $docFileName,
        $vendorId,
        $belongsToVendorId,
        $agent = null
    ) {
        $newPolicyCreated = false;

        foreach ($insuranceInfo[$policyKey]['valueArray'] as $policy) {
            $policyObject = $policy['valueObject'];
            $policyNumber = $policyObject["{$type}_policy_number"]['valueString'];
            $effectiveDate = $policyObject["{$type}_eff"]['valueDate'];
            $expirationDate = $policyObject["{$type}_exp"]['valueDate'];

            // Check if the policy already exists.
            $vendorDoc = VendorDoc::where([
                'number'                 => $policyNumber,
                'expiration_date'        => $expirationDate,
                'type'                   => $type,
                'vendor_id'              => $vendorId,
                'belongs_to_vendor_id'   => $belongsToVendorId,
            ])->first();

            if (!$vendorDoc) {
                // Create the new VendorDoc record using the common file reference.
                $vendorDoc = VendorDoc::create([
                    'type'                 => $type,
                    'vendor_id'            => $vendorId,
                    'effective_date'       => $effectiveDate,
                    'expiration_date'      => $expirationDate,
                    'number'               => $policyNumber,
                    'belongs_to_vendor_id' => $belongsToVendorId,
                    'doc_filename'         => $docFileName,
                ]);

                // Associate the Agent if provided.
                if ($agent) {
                    $vendorDoc->agent()->associate($agent);
                    $vendorDoc->save();
                }
                $newPolicyCreated = true;
            }
        }

        return $newPolicyCreated;
    }

    private function moveEmailBasedOnMatchingResults($messageId, $grantId, $matchedVendorId, $matchedBelongsToVendorId)
    {
        $manualAddFolderId = 'AAMkADlmZDViM2ZhLWZkYjUtNGVlZC1iNzRhLTRjMzhmMjQ0MmNmOAAuAAAAAABj7uvVHKHMQqSEZ0xJa9c1AQCwrDriHLtHRZNuXjKXm1MrAAG9ITvDAAA=';
        $processedFolderId = 'AAMkADlmZDViM2ZhLWZkYjUtNGVlZC1iNzRhLTRjMzhmMjQ0MmNmOAAuAAAAAABj7uvVHKHMQqSEZ0xJa9c1AQCwrDriHLtHRZNuXjKXm1MrAAG2_4hNAAA=';
        $failedFolderId = 'AAMkADlmZDViM2ZhLWZkYjUtNGVlZC1iNzRhLTRjMzhmMjQ0MmNmOAAuAAAAAABj7uvVHKHMQqSEZ0xJa9c1AQCwrDriHLtHRZNuXjKXm1MrAAG9ITvBAAA=';

        // If either matching value is missing, move email to MANUAL ADD folder.
        if (is_null($matchedVendorId) || is_null($matchedBelongsToVendorId)) {
            $this->nylasService->moveEmailToFolder($messageId, $manualAddFolderId, $grantId);
        } else {
            // Otherwise, move it to the processed folder.
            $this->nylasService->moveEmailToFolder($messageId, $processedFolderId, $grantId);
        }
    }

    public function matchBusinessNameAndReturnId($valueString)
    {
        // Normalize the input string
        $normalizedValue = $this->normalizeBusinessName($valueString);

        $bestMatchId = null;
        $highestSimilarity = 0;

        // Fetch IDs and business names from the database
        $businessNames = Vendor::withoutGLobalScopes()->select('id', 'business_name')->get();

        foreach ($businessNames as $entry) {
            // Normalize the database business name
            $normalizedBusinessName = $this->normalizeBusinessName($entry->business_name);

            // Compute similarity using a combination of Levenshtein distance and word overlap
            $levenshteinDistance = levenshtein($normalizedValue, $normalizedBusinessName);
            $inputWords = explode(' ', $normalizedValue);
            $dbWords = explode(' ', $normalizedBusinessName);
            $wordOverlap = count(array_intersect($inputWords, $dbWords)) / max(count($inputWords), 1);

            // Calculate a combined similarity score
            $similarityScore = ($wordOverlap * 150) - $levenshteinDistance;

            // Only consider results with very high similarity
            if ($similarityScore > 80 && $similarityScore > $highestSimilarity) { // Adjust threshold
                $bestMatchId = $entry->id;
                $highestSimilarity = $similarityScore;
            }
        }

        return $bestMatchId;
    }

    public function normalizeBusinessName($value)
    {
        return Str::of($value)
            ->trim()
            ->replace(['.', ',', '&', 'Inc', 'Co', 'DBA', '\\'], '') // Remove punctuation, special characters, and suffixes
            ->replace('  ', ' ') // Remove extra spaces
            ->lower(); // Convert to lowercase
    }
}

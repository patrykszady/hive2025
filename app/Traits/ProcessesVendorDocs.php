<?php

namespace App\Traits;

use App\Models\Agent;
use App\Models\Vendor;
use App\Models\VendorDoc;
use App\Services\GooglePlacesService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Searchy;

trait ProcessesVendorDocs
{
    protected $googlePlacesService;

    protected function getGooglePlacesService()
    {
        if (!$this->googlePlacesService) {
            // Now we can instantiate it directly since it's imported.
            $this->googlePlacesService = new GooglePlacesService();
        }
        return $this->googlePlacesService;
    }

    public function handleVendorDocProcessing(
        $filePath,
        $docType,
        $vendorId = null,
        $belongsToVendorId = null,
        $messageId = null,
        $grantId = null
    ) {
        // 1. Extract OCR data.
        $insuranceInfo = $this->extractDataFromFile($filePath, $docType);

        // 2 & 3. Resolve vendor IDs based on OCR for insured and holder.
        $calculatedVendorId = $this->resolveVendorId($insuranceInfo, 'insured_name', 'insured_address');
        $calculatedBelongsToVendorId = $this->resolveVendorId($insuranceInfo, 'holder_name', 'holder_address');

        // 4. If explicit IDs were provided and they don't match the calculated values, stop processing.
        if (($vendorId && $vendorId != $calculatedVendorId) || ($belongsToVendorId && $belongsToVendorId != $calculatedBelongsToVendorId)) {
            return false;
        }

        // 5. Decide which IDs to use.
        $matchedVendorId = $calculatedVendorId ?: $vendorId;
        $matchedBelongsToVendorId = $calculatedBelongsToVendorId ?: $belongsToVendorId;

        // 6. For email-based processing, move email based on matching results.
        if (isset($messageId) && isset($grantId)) {
            $this->moveEmailBasedOnMatchingResults(
                $messageId,
                $grantId,
                $matchedVendorId,
                $matchedBelongsToVendorId
            );
        }

        // 7. Stop further processing if either vendor ID is missing.
        if (is_null($matchedVendorId) || is_null($matchedBelongsToVendorId)) {
            return false;
        }

        // 8. Process/create the Agent record if OCR returned agent details.
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

        // 9. Create a permanent file name and new storage path.
        $fileName = "{$matchedBelongsToVendorId}-{$matchedVendorId}-" . now()->format('Y-m-d-H-i-s') . ".{$docType}";
        $newFilePath = "vendor_docs/{$fileName}";
        Storage::disk('files')->move($filePath, $newFilePath);

        // 10. Process policy types in a loop.
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
                $newFilePath,   // Permanent storage location.
                $fileName,      // File name saved in DB.
                $matchedVendorId,
                $matchedBelongsToVendorId,
                $agent          // Associate agent if available.
            );
            if ($result === true) {
                $newPolicyCreated = true;
            }
        }

        // 11. If no new vendorDoc was created (i.e. duplicate), delete the permanent file.
        if (!$newPolicyCreated) {
            Storage::disk('files')->delete($newFilePath);
        }
    }

    private function extractDataFromFile($filePath, $docType)
    {
        $document_model = env('AZURE_CUSTOM_MODEL_COI');
        $result = app(\App\Http\Controllers\ReceiptController::class)
            ->azure_docs_api($filePath, $document_model, $docType)['analyzeResult']['documents'][0]['fields'];
        return $result;
    }

    //Resolve the vendor ID by name with a fallback to address.
    protected function resolveVendorId(array $insuranceInfo, $nameKey, $addressKey)
    {
        $calculatedId = $this->matchBusinessNameAndReturnId($insuranceInfo[$nameKey]['valueString'] ?? '');
        if (empty($calculatedId) && isset($insuranceInfo[$addressKey]['valueString'])) {
            $calculatedId = $this->fallbackToAddress($insuranceInfo[$addressKey]['valueString']);
        }
        return $calculatedId;
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

        // Check if 'valueArray' exists AND is a non-empty array.
        if (empty($insuranceInfo[$policyKey]['valueArray']) || !is_array($insuranceInfo[$policyKey]['valueArray'])) {
            Log::warning("processPolicies: Missing or invalid 'valueArray' for policy key: ", [$insuranceInfo]);
            return $newPolicyCreated;
        }

        foreach ($insuranceInfo[$policyKey]['valueArray'] as $policy) {
            if (!isset($policy['valueObject'])) {
                Log::warning("processPolicies: Missing 'valueObject' in policy item for key: ", [$insuranceInfo]);
                continue;
            }

            $policyObject = $policy['valueObject'];
            $policyNumber = $policyObject["{$type}_policy_number"]['valueString'] ?? null;
            $effectiveDate = $policyObject["{$type}_eff"]['valueDate'] ?? null;
            $expirationDate = $policyObject["{$type}_exp"]['valueDate'] ?? null;

            // Ensure required fields are present.
            if (!$policyNumber || !$effectiveDate || !$expirationDate) {
                Log::warning("processPolicies: Incomplete policy data for key: ", [$insuranceInfo]);
                continue;
            }

            $vendorDoc = VendorDoc::withoutGlobalScopes()->firstOrCreate(
                [
                    'number'                 => $policyNumber,
                    'expiration_date'        => $expirationDate,
                    'type'                   => $type,
                    'vendor_id'              => $vendorId,
                    'belongs_to_vendor_id'   => $belongsToVendorId,
                ],
                [
                    'effective_date'         => $effectiveDate,
                    'doc_filename'           => $docFileName,
                ]
            );

            if ($vendorDoc->wasRecentlyCreated && $agent) {
                $vendorDoc->agent()->associate($agent);
                $vendorDoc->save();
            }

            if ($vendorDoc->wasRecentlyCreated) {
                $newPolicyCreated = true;
            }
        }
        return $newPolicyCreated;
    }

    //Move emails based on matching results.
    private function moveEmailBasedOnMatchingResults($messageId, $grantId, $matchedVendorId, $matchedBelongsToVendorId)
    {
        $manualAddFolderId = 'AAMkADlmZDViM2ZhLWZkYjUtNGVlZC1iNzRhLTRjMzhmMjQ0MmNmOAAuAAAAAABj7uvVHKHMQqSEZ0xJa9c1AQCwrDriHLtHRZNuXjKXm1MrAAG9ITvDAAA=';
        $processedFolderId = 'AAMkADlmZDViM2ZhLWZkYjUtNGVlZC1iNzRhLTRjMzhmMjQ0MmNmOAAuAAAAAABj7uvVHKHMQqSEZ0xJa9c1AQCwrDriHLtHRZNuXjKXm1MrAAG2_4hNAAA=';
        $failedFolderId = 'AAMkADlmZDViM2ZhLWZkYjUtNGVlZC1iNzRhLTRjMzhmMjQ0MmNmOAAuAAAAAABj7uvVHKHMQqSEZ0xJa9c1AQCwrDriHLtHRZNuXjKXm1MrAAG9ITvBAAA=';

        if (is_null($matchedVendorId) || is_null($matchedBelongsToVendorId)) {
            $this->nylasService->moveEmailToFolder($messageId, $manualAddFolderId, $grantId);
        } else {
            $this->nylasService->moveEmailToFolder($messageId, $processedFolderId, $grantId);
        }
    }

    /**
     * Use Searchy to match a vendor by the business name.
     * The input value is normalized via normalizeBusinessName.
     */
    public function matchBusinessNameAndReturnId($valueString)
    {
        $normalizedQuery = $this->normalizeBusinessName($valueString);

        $results = Searchy::search('vendors')
            ->fields('business_name')
            ->query($normalizedQuery)
            ->get();

        if ($results->isNotEmpty()) {
            return $results->first()->id;
        }
        return null;
    }

    /**
     * Normalize a business name string.
     */
    public function normalizeBusinessName($value)
    {
        return Str::of($value)
            ->trim()
            ->replace(['.', ',', '&', 'Inc', 'Co', 'DBA', '\\'], '')
            ->replaceMatches('/\s+/', ' ')
            ->lower()
            ->__toString();
    }

    /**
     * Fallback method: Match vendor by comparing addresses.
     * This method uses GooglePlacesService to process and parse an OCR address
     * and builds a composite address string, then compares it against vendor records.
     */
    public function fallbackToAddress($addressString)
    {
        // Use the lazy-loaded GooglePlacesService.
        $googleService = $this->getGooglePlacesService();

        // Get autocomplete suggestions from Google Places.
        $suggestions = $googleService->getAutocompleteSuggestions($addressString);
        if (empty($suggestions)) {
            return null;
        }

        // Automatically take the first suggestion as the best match.
        $placeId = $suggestions[0]['place_id'] ?? null;
        if (!$placeId) {
            return null;
        }

        // Get detailed place information.
        $parsedAddress = $googleService->getPlaceDetails($placeId);
        if (empty($parsedAddress)) {
            return null;
        }

        // Ensure all required address components are available.
        if (
            !isset($parsedAddress['street_number'],
                   $parsedAddress['route'],
                   $parsedAddress['locality'],
                   $parsedAddress['administrative_area_level_1'],
                   $parsedAddress['postal_code'])
        ) {
            return null;
        }

        // Build a composite address string.
        $composite = trim($parsedAddress['street_number'] . ' ' . $parsedAddress['route']) . ', ' .
                     trim($parsedAddress['locality']) . ', ' .
                     trim($parsedAddress['administrative_area_level_1']) . ' ' .
                     trim($parsedAddress['postal_code']);

        // Normalize the composite address.
        $normalizedComposite = $this->normalizeBusinessName($composite);

        // Loop through vendors to find the best matching composite address.
        $vendors = Vendor::withoutGlobalScopes()->get();
        $bestMatchId = null;
        $highestSim = 0;
        foreach ($vendors as $vendor) {
            $vendorComposite = trim(
                $vendor->address . ' ' . $vendor->city . ', ' . $vendor->state . ' ' . $vendor->zip_code
            );
            $normalizedVendorComposite = $this->normalizeBusinessName($vendorComposite);
            similar_text($normalizedVendorComposite, $normalizedComposite, $percent);
            if ($percent > $highestSim && $percent > 80) { // 80% threshold (adjust if needed)
                $highestSim = $percent;
                $bestMatchId = $vendor->id;
            }
        }

        return $bestMatchId;
    }
}

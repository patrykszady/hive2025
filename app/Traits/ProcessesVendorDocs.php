<?php

namespace App\Traits;

use App\Models\Agent;
use App\Models\Vendor;
use App\Models\VendorDoc;
use App\Services\GooglePlacesService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

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
        $grantId = null,
        $sourceLabel = null
    ) {
        // Normalize the file path
        $normalizedFilePath = ltrim($filePath, 'files/');

        try {
            // 1. Extract OCR data
            $insuranceInfo = $this->extractDataFromFile($normalizedFilePath, $docType);
            
            // 2. Validate required fields have sufficient confidence and data
            $requiredFields = [
                'insured_name' => 'Insured Name',
                'holder_name' => 'Certificate Holder Name',
            ];
            $minConfidence = 0.4;
            
            $missingFields = [];
            foreach ($requiredFields as $fieldKey => $fieldLabel) {
                $field = $insuranceInfo[$fieldKey] ?? null;
                $hasValue = !empty($field['valueString'] ?? '');
                $hasConfidence = ($field['confidence'] ?? 0) >= $minConfidence;
                
                if (!$hasValue || !$hasConfidence) {
                    $missingFields[] = $fieldLabel . ' (confidence: ' . ($field['confidence'] ?? 0) . ')';
                }
            }
            
            // If required fields are missing, send to manual review
            if (!empty($missingFields)) {
                Log::channel('vendor_docs')->warning('Missing or low confidence required fields - sending to manual review', [
                    'file' => $normalizedFilePath,
                    'missing_fields' => $missingFields,
                    'message_id' => $messageId,
                ]);
                
                if (isset($messageId) && isset($grantId)) {
                    $this->moveEmailBasedOnMatchingResults($messageId, $grantId, null, null);
                }
                return false;
            }
            
            // 3. Resolve vendor IDs
            $calculatedVendorId = $this->resolveVendorId($insuranceInfo, 'insured_name', 'insured_address');
            $calculatedBelongsToVendorId = $this->resolveVendorId($insuranceInfo, 'holder_name', 'holder_address');

            // 4. Validate vendor IDs
            if (($vendorId && $vendorId != $calculatedVendorId) || ($belongsToVendorId && $belongsToVendorId != $calculatedBelongsToVendorId)) {
                Log::channel('vendor_docs')->warning('Vendor ID mismatch', [
                    'file' => $normalizedFilePath,
                    'provided_vendor_id' => $vendorId,
                    'calculated_vendor_id' => $calculatedVendorId,
                    'provided_belongs_to_vendor_id' => $belongsToVendorId,
                    'calculated_belongs_to_vendor_id' => $calculatedBelongsToVendorId
                ]);
                return false;
            }

            // 5. Use calculated or provided IDs
            $matchedVendorId = $calculatedVendorId ?: $vendorId;
            $matchedBelongsToVendorId = $calculatedBelongsToVendorId ?: $belongsToVendorId;

            // 6. Check if we have valid vendor IDs before attempting policy processing
            if (is_null($matchedVendorId) || is_null($matchedBelongsToVendorId)) {
                Log::channel('vendor_docs')->error('Unable to match vendor information', [
                    'file' => $normalizedFilePath,
                    'insured_name' => $insuranceInfo['insured_name']['valueString'] ?? 'not found',
                    'holder_name' => $insuranceInfo['holder_name']['valueString'] ?? 'not found'
                ]);
                if (isset($messageId) && isset($grantId)) {
                    $this->moveEmailBasedOnMatchingResults($messageId, $grantId, null, null);
                }
                return false;
            }

            // 7. Process agent if available
            $agent = $this->processAgent($insuranceInfo);

            // 8. Generate permanent filename and copy file
            $labelSuffix = $sourceLabel ? '-'.$sourceLabel : '';
            $fileName = "{$matchedBelongsToVendorId}-{$matchedVendorId}-" . now()->format('Y-m-d-H-i-s') . "{$labelSuffix}.{$docType}";
            $newFilePath = "vendor_docs/{$fileName}";

            if (!$this->copyToPermanentLocation($normalizedFilePath, $newFilePath)) {
                Log::channel('vendor_docs')->error('Failed to copy file to permanent location', [
                    'file' => $normalizedFilePath,
                    'from' => $normalizedFilePath,
                    'to' => $newFilePath
                ]);
                return false;
            }

            // 9. Process all policy types and gather validation results
            $policyProcessingResult = $this->processAllPolicyTypes(
                $insuranceInfo,
                $fileName,
                $matchedVendorId,
                $matchedBelongsToVendorId,
                $agent,
                $messageId
            );

            $newPolicyCreated = $policyProcessingResult['created'];
            $missingRequiredFields = $policyProcessingResult['missing_requirements'];

            if (isset($messageId) && isset($grantId)) {
                if ($missingRequiredFields) {
                    $this->moveEmailBasedOnMatchingResults($messageId, $grantId, null, null);
                } else {
                    $this->moveEmailBasedOnMatchingResults($messageId, $grantId, $matchedVendorId, $matchedBelongsToVendorId);
                }
            }

            // 10. Cleanup
            if ($newPolicyCreated) {
                Storage::disk('files')->delete($normalizedFilePath);
                return true;
            } else {
                // No policies created (duplicate) - cleanup permanent file but keep temp file for debugging
                Storage::disk('files')->delete($newFilePath);
                return false;
            }

        } catch (\Exception $e) {
            Log::channel('vendor_docs')->error('Exception during document processing', [
                'file' => $normalizedFilePath,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Keep temp file for debugging, only cleanup permanent file if it exists
            if (isset($newFilePath)) {
                Storage::disk('files')->delete($newFilePath);
            }

            return false;
        }
    }

    private function processAgent($insuranceInfo)
    {
        if (isset($insuranceInfo['agent_email']['valueString']) && !empty($insuranceInfo['agent_email']['valueString'])) {
            return Agent::firstOrCreate(
                ['email' => $insuranceInfo['agent_email']['valueString']],
                [
                    'name' => $insuranceInfo['agent_name']['valueString'] ?? null,
                    'business_name' => $insuranceInfo['agent_agency']['valueString'] ?? null,
                    'address' => $insuranceInfo['agent_agency_address']['valueString'] ?? null,
                    'phone' => $insuranceInfo['agent_phone']['content'] ?? null,
                ]
            );
        }
        return null;
    }

    private function copyToPermanentLocation($from, $to)
    {
        // Ensure directory exists
        if (!Storage::disk('files')->exists('vendor_docs')) {
            Storage::disk('files')->makeDirectory('vendor_docs');
        }

        // Copy file
        $success = Storage::disk('files')->copy($from, $to);

        return $success && Storage::disk('files')->exists($to);
    }

    private function processAllPolicyTypes($insuranceInfo, $fileName, $vendorId, $belongsToVendorId, $agent, ?string $messageId = null): array
    {
        $policyTypes = [
            ['policyKey' => 'general_multi', 'type' => 'general'],
            ['policyKey' => 'professional_multi', 'type' => 'professional'],
            ['policyKey' => 'workers_multi', 'type' => 'workers'],
        ];

        $aggregate = [
            'created' => false,
            'missing_requirements' => false,
        ];
        foreach ($policyTypes as $policy) {
            $result = $this->processPolicies(
                $insuranceInfo,
                $policy['policyKey'],
                $policy['type'],
                $fileName,
                $vendorId,
                $belongsToVendorId,
                $agent,
                $messageId
            );

            if ($result['created']) {
                $aggregate['created'] = true;
            }

            if ($result['missing_requirements']) {
                $aggregate['missing_requirements'] = true;
            }
        }

        return $aggregate;
    }

    private function processPolicies($insuranceInfo, $policyKey, $type, $fileName, $vendorId, $belongsToVendorId, $agent = null, ?string $messageId = null): array
    {
        $result = [
            'created' => false,
            'missing_requirements' => false,
        ];

        // Check if policy data exists
        if (empty($insuranceInfo[$policyKey]['valueArray']) || !is_array($insuranceInfo[$policyKey]['valueArray'])) {
            return $result;
        }

        $completePolicies = [];
        $partialBuckets = [];

        foreach ($insuranceInfo[$policyKey]['valueArray'] as $policy) {
            if (!isset($policy['valueObject'])) {
                continue;
            }

            $policyObject = $policy['valueObject'];

            $policyNumber = $policyObject["{$type}_policy_number"]['valueString'] ?? null;
            $effectiveDate = $policyObject["{$type}_eff"]['valueDate'] ?? $policyObject["{$type}_eff"]['valueString'] ?? null;
            $expirationDate = $policyObject["{$type}_exp"]['valueDate'] ?? $policyObject["{$type}_exp"]['valueString'] ?? null;

            if ($policyNumber && $effectiveDate && $expirationDate) {
                $key = $policyNumber.'|'.$effectiveDate.'|'.$expirationDate;
                $completePolicies[$key] = [
                    'policy_number' => $policyNumber,
                    'effective_date' => $effectiveDate,
                    'expiration_date' => $expirationDate,
                ];
                continue;
            }

            $bucketKey = $policyNumber ?: 'unknown';
            $partialBuckets[$bucketKey][] = [
                'policy_number' => $policyNumber,
                'effective_date' => $effectiveDate,
                'expiration_date' => $expirationDate,
            ];
        }

        if (isset($partialBuckets['unknown'])) {
            $knownPolicyNumbers = array_values(array_filter(array_keys($partialBuckets), fn ($key) => $key !== 'unknown'));
            if (count($knownPolicyNumbers) === 1) {
                $targetKey = $knownPolicyNumbers[0];
                $partialBuckets[$targetKey] = array_merge($partialBuckets[$targetKey] ?? [], $partialBuckets['unknown']);
                unset($partialBuckets['unknown']);
            }
        }

        $mergedPolicies = $completePolicies;

        foreach ($partialBuckets as $bucketKey => $partials) {
            if ($bucketKey !== 'unknown' && collect($completePolicies)->contains(fn ($policy) => $policy['policy_number'] === $bucketKey)) {
                continue;
            }

            $merged = [
                'policy_number' => null,
                'effective_date' => null,
                'expiration_date' => null,
            ];

            foreach ($partials as $partial) {
                if (!$merged['policy_number'] && !empty($partial['policy_number'])) {
                    $merged['policy_number'] = $partial['policy_number'];
                }
                if (!$merged['effective_date'] && !empty($partial['effective_date'])) {
                    $merged['effective_date'] = $partial['effective_date'];
                }
                if (!$merged['expiration_date'] && !empty($partial['expiration_date'])) {
                    $merged['expiration_date'] = $partial['expiration_date'];
                }
            }

            $key = ($merged['policy_number'] ?: $bucketKey).'|'.($merged['effective_date'] ?: 'unknown').'|'.($merged['expiration_date'] ?: 'unknown');
            $mergedPolicies[$key] = $merged;
        }

        foreach ($mergedPolicies as $policyData) {
            $policyNumber = $policyData['policy_number'] ?? null;
            $effectiveDate = $policyData['effective_date'] ?? null;
            $expirationDate = $policyData['expiration_date'] ?? null;

            if (!$policyNumber || !$effectiveDate || !$expirationDate) {
                Log::channel('vendor_docs')->warning('Incomplete policy data', [
                    'file' => $fileName,
                    'type' => $type,
                    'policy_number' => $policyNumber,
                    'effective_date' => $effectiveDate,
                    'expiration_date' => $expirationDate,
                    'message_id' => $messageId,
                ]);
                $result['missing_requirements'] = true;
                continue;
            }

            $vendorDoc = VendorDoc::withoutGlobalScopes()->firstOrCreate(
                [
                    'number' => $policyNumber,
                    'expiration_date' => $expirationDate,
                    'type' => $type,
                    'vendor_id' => $vendorId,
                    'belongs_to_vendor_id' => $belongsToVendorId,
                ],
                [
                    'effective_date' => $effectiveDate,
                    'doc_filename' => $fileName,
                ]
            );

            if ($vendorDoc->wasRecentlyCreated && $agent) {
                $vendorDoc->agent()->associate($agent);
                $vendorDoc->save();
            }

            if ($vendorDoc->wasRecentlyCreated) {
                $result['created'] = true;
            }
        }

        return $result;
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
        $name = $this->normalizeVendorName($insuranceInfo[$nameKey]['valueString'] ?? '');
        $address = $insuranceInfo[$addressKey]['valueString'] ?? '';
        
        // Try to match by business name first (with address for disambiguation)
        $calculatedId = $this->matchBusinessNameAndReturnId($name, $address);
        
        // Fallback to address matching if name matching fails
        if (empty($calculatedId) && !empty($address)) {
            $calculatedId = $this->fallbackToAddress($address);
        }
        
        return $calculatedId;
    }

    private function normalizeVendorName(string $name): string
    {
        $trimmed = trim($name);
        if ($trimmed === '') {
            return '';
        }

        return trim((string) preg_replace('/%.*$/', '', $trimmed));
    }

    protected function matchBusinessNameAndReturnId($businessName, $businessAddress = null)
    {
        if (empty($businessName)) {
            return null;
        }

        $vendors = Vendor::withoutGlobalScopes()->get();
        $bestMatch = null;
        $highestScore = 0;
        $threshold = 0.7; // Minimum similarity score (0-1)

        foreach ($vendors as $vendor) {
            if (empty($vendor->business_name)) {
                continue;
            }

            // Skip Retail vendors - they should never be matched for insurance certificates
            if ($vendor->business_type === 'Retail') {
                continue;
            }

            $score = $this->calculateSimilarityScore($businessName, $vendor->business_name);

            // Factor in address if available - this helps differentiate similar names
            if (!empty($businessAddress)) {
                if (!empty($vendor->address)) {
                    $addressScore = $this->calculateSimilarityScore($businessAddress, $vendor->address);
                    // Weight name heavily (70%) but address significantly (30%) to differentiate
                    $score = ($score * 0.7) + ($addressScore * 0.3);
                } else {
                    // If we have an address from OCR but vendor has no address, penalize heavily
                    // This prevents matching to generic vendors like "Costco" that lack location data
                    $score = $score * 0.5;
                }
            }

            if ($score > $highestScore && $score >= $threshold) {
                $highestScore = $score;
                $bestMatch = $vendor;
            }
        }

        return $bestMatch ? $bestMatch->id : null;
    }

    private function calculateSimilarityScore($string1, $string2)
    {
        // Normalize strings
        $str1 = $this->normalizeString($string1);
        $str2 = $this->normalizeString($string2);

        // If exact match after normalization
        if ($str1 === $str2) {
            return 1.0;
        }

        // Extract key words (non-common business words)
        $words1 = array_filter(explode(' ', $str1), function($word) {
            return strlen($word) > 2 && !in_array($word, ['inc', 'llc', 'corp', 'ltd']);
        });
        $words2 = array_filter(explode(' ', $str2), function($word) {
            return strlen($word) > 2 && !in_array($word, ['inc', 'llc', 'corp', 'ltd']);
        });

        // Check for unique distinguishing words - if one has a word the other doesn't, penalize
        $uniqueWords1 = array_diff($words1, $words2);
        $uniqueWords2 = array_diff($words2, $words1);
        $totalWords = count($words1) + count($words2);
        $uniqueWordPenalty = ($totalWords > 0) ? (count($uniqueWords1) + count($uniqueWords2)) / $totalWords : 0;

        // Calculate multiple similarity metrics
        $similarity = 0;
        similar_text($str1, $str2, $similarity);
        $similarTextScore = $similarity / 100;

        $levenshtein = levenshtein($str1, $str2);
        $maxLen = max(strlen($str1), strlen($str2));
        $levenshteinScore = $maxLen > 0 ? 1 - ($levenshtein / $maxLen) : 0;

        // Check for substring matches
        $substringScore = 0;
        if (strpos($str1, $str2) !== false || strpos($str2, $str1) !== false) {
            $substringScore = 0.8;
        }

        // Get the best base score
        $baseScore = max($similarTextScore, $levenshteinScore, $substringScore);
        
        // Apply penalty for unique words (e.g., "quality" vs "hardwood")
        $finalScore = $baseScore * (1 - ($uniqueWordPenalty * 0.5));

        return $finalScore;
    }

    private function normalizeString($string)
    {
        return Str::of($string)
            ->lower()
            ->replace([',', '.', 'inc', 'llc', 'corp', 'ltd', 'co'], '')
            ->replaceMatches('/[^a-z0-9\s]/', '')
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->toString();
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

        // Fix: Use the correct method name
        $normalizedComposite = $this->normalizeString($composite);

        // Loop through vendors to find the best matching composite address.
        $vendors = Vendor::withoutGlobalScopes()->get();
        $bestMatchId = null;
        $highestSim = 0;
        foreach ($vendors as $vendor) {
            $vendorComposite = trim(
                $vendor->address . ' ' . $vendor->city . ', ' . $vendor->state . ' ' . $vendor->zip_code
            );
            // Fix: Use the correct method name
            $normalizedVendorComposite = $this->normalizeString($vendorComposite);
            similar_text($normalizedVendorComposite, $normalizedComposite, $percent);
            if ($percent > $highestSim && $percent > 80) { // 80% threshold (adjust if needed)
                $highestSim = $percent;
                $bestMatchId = $vendor->id;
            }
        }

        return $bestMatchId;
    }
}

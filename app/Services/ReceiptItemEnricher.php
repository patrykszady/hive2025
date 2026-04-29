<?php

namespace App\Services;

class ReceiptItemEnricher
{
    /**
     * Match supplement items to existing receipt items by description similarity
     * and produce a list of update rows describing which Manufacturer / MPN values
     * should be filled in from the supplement.
     *
     * @param array<int,array<string,mixed>> $existingItems
     * @param array<int,array<string,mixed>> $supplementItems
     * @return array<int,array{
     *     index:int,
     *     supplement_index:int|null,
     *     similarity:float|null,
     *     existing_description:string,
     *     new_manufacturer:?string,
     *     new_mpn:?string,
     *     changed:bool,
     * }>
     */
    public function planMerge(array $existingItems, array $supplementItems, float $threshold = 0.34): array
    {
        $supplementTokens = [];
        foreach ($supplementItems as $i => $sup) {
            $supplementTokens[$i] = $this->tokenize((string) ($sup['Description'] ?? ''));
        }

        $usedSupplement = [];
        $updates = [];

        foreach ($existingItems as $idx => $item) {
            $desc = (string) ($item['Description'] ?? '');
            $tokens = $this->tokenize($desc);
            $existingMfg = trim((string) ($item['Manufacturer'] ?? ''));
            $existingMpn = trim((string) ($item['ManufacturerPartNumber'] ?? ''));
            $qty = (int) ($item['Quantity'] ?? 0);

            $best = null;
            $bestScore = 0.0;
            foreach ($supplementItems as $i => $sup) {
                if (isset($usedSupplement[$i])) {
                    continue;
                }
                $score = $this->similarity($tokens, $supplementTokens[$i]);
                if ($qty > 0 && $qty === (int) ($sup['Quantity'] ?? 0)) {
                    $score += 0.05;
                }
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = $i;
                }
            }

            // Be more permissive for rows that explicitly lack Manufacturer/MPN
            // — they exist on the original quote but are missing the data the
            // supplement is meant to provide. Verbose supplement descriptions
            // often don't share enough tokens with terse quotation lines to
            // clear the standard threshold (e.g. "C3-455 CLEANSING TOILET
            // SEAT WHITE" vs "Purewash E820 Elongated Bidet Toilet Seat with
            // Remote Control"), but they're still the same product.
            $effectiveThreshold = ($existingMfg === '' && $existingMpn === '')
                ? min($threshold, 0.18)
                : $threshold;

            $row = [
                'index'                => $idx,
                'supplement_index'     => null,
                'similarity'           => null,
                'existing_description' => $desc,
                'new_manufacturer'     => null,
                'new_mpn'              => null,
                'changed'              => false,
            ];

            if ($best !== null && $bestScore >= $effectiveThreshold) {
                $usedSupplement[$best] = true;
                $sup = $supplementItems[$best];
                $supMfg = trim((string) ($sup['Manufacturer'] ?? ''));
                // Supplement OCR puts the manufacturer SKU into VendorCode and
                // leaves ManufacturerPartNumber null. Treat VendorCode as MPN
                // when no explicit MPN is present.
                $supMpn = $this->sanitizeMpn(
                    $sup['ManufacturerPartNumber'] ?? $sup['VendorCode'] ?? null,
                    $supMfg,
                );

                $newMfg = $existingMfg === '' && $supMfg !== '' ? $supMfg : null;
                $newMpn = $existingMpn === '' && $supMpn !== '' ? $supMpn : null;

                $row['supplement_index'] = $best;
                $row['similarity']       = $bestScore;
                $row['new_manufacturer'] = $newMfg;
                $row['new_mpn']          = $newMpn;
                $row['changed']          = $newMfg !== null || $newMpn !== null;
            }

            $updates[] = $row;
        }

        return $updates;
    }

    /**
     * Apply planMerge() updates to an existing items array.
     *
     * @param array<int,array<string,mixed>> $existingItems
     * @param array<int,array<string,mixed>> $updates
     * @return array<int,array<string,mixed>>
     */
    public function applyUpdates(array $existingItems, array $updates): array
    {
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

        return $existingItems;
    }

    /**
     * Return the supplement items that planMerge() did not match to any existing
     * row. These are NEW lines that should be appended to the receipt (e.g.
     * mirrors / accessories added by the designer that weren't on the original PO).
     *
     * Items with empty Description are filtered out — the supplement OCR
     * sometimes emits header / spacer rows.
     *
     * Dedup pass: a supplement is dropped (not appended) if its sanitised MPN
     * already exists on the receipt, or if its description has a relaxed
     * Jaccard overlap (>= 0.22) with any existing item — preventing duplicates
     * from re-occurring on already-supplemented receipts.
     *
     * @param array<int,array<string,mixed>> $existingItems
     * @param array<int,array<string,mixed>> $supplementItems
     * @param array<int,array<string,mixed>> $updates
     * @return array<int,array<string,mixed>>
     */
    public function unmatchedSupplementItems(array $existingItems, array $supplementItems, array $updates, float $dedupThreshold = 0.22): array
    {
        $used = [];
        foreach ($updates as $u) {
            if ($u['supplement_index'] !== null) {
                $used[$u['supplement_index']] = true;
            }
        }

        // Build lookup of existing MPNs (sanitised, uppercased, alphanum-only).
        $existingMpns = [];
        $existingDescTokens = [];
        foreach ($existingItems as $idx => $ex) {
            $mpn = $this->normaliseMpnKey($this->sanitizeMpn(
                $ex['ManufacturerPartNumber'] ?? null,
                (string) ($ex['Manufacturer'] ?? ''),
            ));
            if ($mpn !== '') {
                $existingMpns[$mpn] = true;
            }
            $existingDescTokens[$idx] = $this->tokenize((string) ($ex['Description'] ?? ''));
        }

        $unmatched = [];
        foreach ($supplementItems as $i => $sup) {
            if (isset($used[$i])) {
                continue;
            }
            $desc = trim((string) ($sup['Description'] ?? ''));
            if ($desc === '') {
                continue;
            }
            $supMfg = (string) ($sup['Manufacturer'] ?? '');
            $supMpn = $this->sanitizeMpn(
                $sup['ManufacturerPartNumber'] ?? $sup['VendorCode'] ?? null,
                $supMfg,
            );

            // Drop if MPN already present on the receipt.
            $supMpnKey = $this->normaliseMpnKey($supMpn);
            if ($supMpnKey !== '' && isset($existingMpns[$supMpnKey])) {
                continue;
            }

            // Drop if description has any relaxed-threshold overlap with an
            // existing line (catches near-duplicate wording planMerge missed).
            $supTokens = $this->tokenize($desc);
            $isDup = false;
            foreach ($existingDescTokens as $tokens) {
                if ($this->similarity($supTokens, $tokens) >= $dedupThreshold) {
                    $isDup = true;
                    break;
                }
            }
            if ($isDup) {
                continue;
            }

            // Normalise to the existing receipt-item shape.
            if (empty($sup['ManufacturerPartNumber']) && $supMpn !== '') {
                $sup['ManufacturerPartNumber'] = $supMpn;
            } elseif (! empty($sup['ManufacturerPartNumber'])) {
                $sup['ManufacturerPartNumber'] = $this->sanitizeMpn(
                    $sup['ManufacturerPartNumber'],
                    $supMfg,
                );
            }
            // Force re-scrape on the new row.
            $sup['product_url'] = null;
            $sup['image_url']   = null;
            $unmatched[] = $sup;
        }

        return $unmatched;
    }

    /**
     * Strip the manufacturer name (or known brand prefix) from the front of an
     * MPN. Many material-order OCRs emit values like "BRIZORP72414PC" where
     * the brand name is concatenated to the real part number.
     */
    public function sanitizeMpn(?string $mpn, ?string $manufacturer): string
    {
        $mpn = trim((string) $mpn);
        if ($mpn === '') {
            return '';
        }

        $mfg = strtoupper((string) preg_replace('/[^A-Za-z]/', '', (string) $manufacturer));
        if ($mfg === '' || strlen($mfg) < 3) {
            return $mpn;
        }

        // Walk the MPN letter-by-letter (skipping non-alpha) and consume a
        // matching manufacturer-prefix. Preserves original case + separators.
        $upperMpn = strtoupper($mpn);
        $alphaOnly = preg_replace('/[^A-Z]/', '', $upperMpn);
        if (! is_string($alphaOnly) || strlen($alphaOnly) <= strlen($mfg)) {
            return $mpn;
        }
        if (! str_starts_with($alphaOnly, $mfg)) {
            return $mpn;
        }

        // Consume characters from the original $mpn until we've eaten $mfg
        // letters. Strip any leading non-alphanumerics that remain.
        $needed = strlen($mfg);
        $i = 0;
        $len = strlen($mpn);
        while ($needed > 0 && $i < $len) {
            $ch = $mpn[$i];
            if (ctype_alpha($ch)) {
                $needed--;
            }
            $i++;
        }
        $stripped = substr($mpn, $i);
        $stripped = ltrim($stripped, " -_/.");

        return $stripped !== '' ? $stripped : $mpn;
    }

    /**
     * Lookup-key for MPN dedup: uppercase, alphanumerics only.
     */
    private function normaliseMpnKey(string $mpn): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $mpn));
    }

    /**
     * @return array<int,string>
     */
    private function tokenize(string $text): array
    {
        $text = strtolower($text);
        $text = (string) preg_replace('/[^a-z0-9]+/', ' ', $text);
        $stop = ['the','and','with','for','an','of','in','to','on','is','by','or','at','single','multi','function','handle','kit'];
        $tokens = array_filter(
            preg_split('/\s+/', trim($text)) ?: [],
            fn ($t) => $t !== '' && strlen($t) >= 2 && ! in_array($t, $stop, true),
        );

        // Lightweight stemming: collapse trailing plural 's'/'es' so words like
        // "stops"/"stop" or "handles"/"handle" align across descriptions.
        $tokens = array_map(function (string $t): string {
            if (strlen($t) > 4 && str_ends_with($t, 'es')) {
                return substr($t, 0, -2);
            }
            if (strlen($t) > 3 && str_ends_with($t, 's') && ! str_ends_with($t, 'ss')) {
                return substr($t, 0, -1);
            }
            return $t;
        }, $tokens);

        return array_values(array_unique($tokens));
    }

    /**
     * Composite similarity that combines Jaccard with smaller-side containment.
     *
     * Containment (intersection / min(|A|,|B|)) catches cases where one side is
     * a verbose supplement description and the other is a terse quotation
     * line: even with low Jaccard, a high containment indicates the shorter
     * description is largely covered by the longer one.
     *
     * @param array<int,string> $a
     * @param array<int,string> $b
     */
    private function similarity(array $a, array $b): float
    {
        $jaccard = $this->jaccard($a, $b);

        if (empty($a) || empty($b)) {
            return $jaccard;
        }

        $intersect = count(array_intersect_key(array_flip($a), array_flip($b)));
        if ($intersect < 2) {
            return $jaccard;
        }

        $minSize = min(count(array_unique($a)), count(array_unique($b)));
        if ($minSize < 3) {
            return $jaccard;
        }

        $containment = $intersect / $minSize;

        return max($jaccard, $containment * 0.7);
    }

    /**
     * @param array<int,string> $a
     * @param array<int,string> $b
     */
    private function jaccard(array $a, array $b): float
    {
        if (empty($a) || empty($b)) {
            return 0.0;
        }
        $ai = array_flip($a);
        $bi = array_flip($b);
        $intersect = count(array_intersect_key($ai, $bi));
        $union = count($ai) + count($bi) - $intersect;

        return $union > 0 ? $intersect / $union : 0.0;
    }
}

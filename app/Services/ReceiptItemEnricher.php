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
                $score = $this->jaccard($tokens, $supplementTokens[$i]);
                if ($qty > 0 && $qty === (int) ($sup['Quantity'] ?? 0)) {
                    $score += 0.05;
                }
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = $i;
                }
            }

            $row = [
                'index'                => $idx,
                'supplement_index'     => null,
                'similarity'           => null,
                'existing_description' => $desc,
                'new_manufacturer'     => null,
                'new_mpn'              => null,
                'changed'              => false,
            ];

            if ($best !== null && $bestScore >= $threshold) {
                $usedSupplement[$best] = true;
                $sup = $supplementItems[$best];
                $supMfg = trim((string) ($sup['Manufacturer'] ?? ''));
                // Supplement OCR puts the manufacturer SKU into VendorCode and
                // leaves ManufacturerPartNumber null. Treat VendorCode as MPN
                // when no explicit MPN is present.
                $supMpn = trim((string) ($sup['ManufacturerPartNumber']
                    ?? $sup['VendorCode']
                    ?? ''));
                $supMpn = $this->sanitizeMpn($supMpn, $supMfg);

                $newMfg = $existingMfg === '' && $supMfg !== '' ? $supMfg : null;
                $newMpn = $existingMpn === '' && $supMpn !== '' ? $supMpn : null;

                $row['supplement_index'] = $best;
                $row['similarity']       = $bestScore;
                $row['new_manufacturer'] = $newMfg;
                $row['new_mpn']          = $newMpn;
                $row['changed']          = $newMfg !== null || $newMpn !== null;
            } elseif ($qty > 0) {
                // Fallback for terse quotation lines that do not share enough
                // lexical overlap with supplement descriptions: pick the first
                // unused supplement row with matching quantity.
                foreach ($supplementItems as $i => $sup) {
                    if (isset($usedSupplement[$i])) {
                        continue;
                    }
                    if ((int) ($sup['Quantity'] ?? 0) !== $qty) {
                        continue;
                    }

                    $supMfg = trim((string) ($sup['Manufacturer'] ?? ''));
                    $supMpn = trim((string) ($sup['ManufacturerPartNumber'] ?? $sup['VendorCode'] ?? ''));
                    $supMpn = $this->sanitizeMpn($supMpn, $supMfg);

                    if ($supMfg === '' && $supMpn === '') {
                        continue;
                    }

                    $usedSupplement[$i] = true;

                    $newMfg = $existingMfg === '' && $supMfg !== '' ? $supMfg : null;
                    $newMpn = $existingMpn === '' && $supMpn !== '' ? $supMpn : null;

                    $row['supplement_index'] = $i;
                    $row['similarity'] = null;
                    $row['new_manufacturer'] = $newMfg;
                    $row['new_mpn'] = $newMpn;
                    $row['changed'] = $newMfg !== null || $newMpn !== null;

                    break;
                }
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

        return array_values(array_unique($tokens));
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

    public function sanitizeMpn(string $mpn, string $manufacturer): string
    {
        $normalizedMpn = strtoupper((string) preg_replace('/[^A-Z0-9]+/i', '', trim($mpn)));
        if ($normalizedMpn === '') {
            return '';
        }

        $normalizedManufacturer = strtoupper((string) preg_replace('/[^A-Z0-9]+/i', '', trim($manufacturer)));

        if ($normalizedManufacturer !== '' && strlen($normalizedManufacturer) >= 3 && str_starts_with($normalizedMpn, $normalizedManufacturer)) {
            $stripped = substr($normalizedMpn, strlen($normalizedManufacturer));
            if ($stripped !== '') {
                return $stripped;
            }
        }

        return $normalizedMpn;
    }

    /**
     * @param array<int,array<string,mixed>> $existingItems
     * @param array<int,array<string,mixed>> $supplementItems
     * @param array<int,array<string,mixed>> $updates
     * @return array<int,array<string,mixed>>
     */
    public function unmatchedSupplementItems(array $existingItems, array $supplementItems, array $updates): array
    {
        $matchedSupplementIndexes = [];
        foreach ($updates as $update) {
            $supplementIndex = $update['supplement_index'] ?? null;
            if (is_int($supplementIndex)) {
                $matchedSupplementIndexes[$supplementIndex] = true;
            }
        }

        $existingMpnSet = [];
        $existingDescriptionTokens = [];
        foreach ($existingItems as $item) {
            $existingMpn = $this->sanitizeMpn(
                (string) ($item['ManufacturerPartNumber'] ?? ''),
                (string) ($item['Manufacturer'] ?? ''),
            );
            if ($existingMpn !== '') {
                $existingMpnSet[$existingMpn] = true;
            }

            $existingDescriptionTokens[] = $this->tokenize((string) ($item['Description'] ?? ''));
        }

        $appended = [];
        $appendedMpnSet = [];

        foreach ($supplementItems as $index => $supplementItem) {
            if (isset($matchedSupplementIndexes[$index])) {
                continue;
            }

            $manufacturer = trim((string) ($supplementItem['Manufacturer'] ?? ''));
            $rawMpn = trim((string) ($supplementItem['ManufacturerPartNumber'] ?? $supplementItem['VendorCode'] ?? ''));
            $sanitizedMpn = $this->sanitizeMpn($rawMpn, $manufacturer);

            if ($sanitizedMpn !== '' && (isset($existingMpnSet[$sanitizedMpn]) || isset($appendedMpnSet[$sanitizedMpn]))) {
                continue;
            }

            $supplementTokens = $this->tokenize((string) ($supplementItem['Description'] ?? ''));
            $isDescriptionDuplicate = false;
            foreach ($existingDescriptionTokens as $tokens) {
                if ($this->jaccard($supplementTokens, $tokens) >= 0.34) {
                    $isDescriptionDuplicate = true;
                    break;
                }
            }

            if ($isDescriptionDuplicate) {
                continue;
            }

            $row = $supplementItem;
            $row['Manufacturer'] = $manufacturer;
            $row['ManufacturerPartNumber'] = $sanitizedMpn;
            $row['product_url'] = $row['product_url'] ?? null;
            $row['image_url'] = $row['image_url'] ?? null;

            if ($sanitizedMpn !== '') {
                $appendedMpnSet[$sanitizedMpn] = true;
            }

            $appended[] = $row;
        }

        return $appended;
    }
}

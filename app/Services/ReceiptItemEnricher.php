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
}

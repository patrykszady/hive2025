<?php

use App\Http\Controllers\ExpenseAutoMatchController;

it('boosts matches when house number digits are OCR-confusable and street letters overlap', function () {
    $controller = new class extends ExpenseAutoMatchController
    {
        public function boost(string $po, string $variant): float
        {
            $poNorm = $this->normalizeText($po);
            $variantNorm = $this->normalizeText($variant);
            $poStreetToken = $this->hasHouseNumberPrefix($poNorm) ? $this->extractStreetToken($poNorm) : '';

            return $this->houseNumberOcrBoostScore($poNorm, $variantNorm, $poStreetToken);
        }
    };

    // Simulate OCR: "126 Kincaid" misread as "146 Chinadia".
    expect($controller->boost('146 Chinadia', '126 Kincaid Dr'))->toBeGreaterThanOrEqual(0.86);
});

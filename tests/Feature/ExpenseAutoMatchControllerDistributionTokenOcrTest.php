<?php

use App\Http\Controllers\ExpenseAutoMatchController;

it('matches OFFICE distribution from OCR token "Offre"', function () {
    $controller = new class extends ExpenseAutoMatchController
    {
        /**
         * @param array<int, array{id:int,normalized:string}> $index
         */
        public function match(string $po, array $index): ?array
        {
            return $this->matchPurchaseOrderToDistribution($po, $index);
        }
    };

    $index = [
        ['id' => 1, 'normalized' => 'office'],
    ];

    $match = $controller->match('Offre', $index);

    expect($match)->not->toBeNull();
    expect($match['distribution_id'])->toBe(1);
    expect($match['score'])->toBeGreaterThanOrEqual(0.70);
});

<?php

use App\Http\Controllers\ExpenseAutoMatchController;

it('handles OCR repeated-letter variations for street-token matching', function () {
    $controller = new class extends ExpenseAutoMatchController
    {
        public function score(string $a, string $b): float
        {
            return $this->similarityScoreWithOcrFixes($a, $b);
        }
    };

    // "DIMARCELA" is a handwritten note that should match "Marcella".
    expect($controller->score('dimarcela', 'marcella'))->toBeGreaterThanOrEqual(0.9);
});

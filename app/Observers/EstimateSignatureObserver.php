<?php

namespace App\Observers;

use App\Models\EstimateSignature;
use App\Services\EstimateAI\DraftCorrections;

class EstimateSignatureObserver
{
    /**
     * A signature fixes what the estimate says: snapshot how far each AI
     * draft on it was corrected before it got here.
     */
    public function created(EstimateSignature $signature): void
    {
        DraftCorrections::finalize((int) $signature->estimate_id);
    }
}

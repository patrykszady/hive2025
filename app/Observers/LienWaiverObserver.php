<?php

namespace App\Observers;

use App\Models\LienWaiver;

class LienWaiverObserver
{
    public function creating(LienWaiver $waiver): void
    {
        if (empty($waiver->document_hash)) {
            $waiver->document_hash = $waiver->computeDocumentHash();
        }
    }
}

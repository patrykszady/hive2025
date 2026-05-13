<?php

namespace App\Observers;

use App\Models\Payment;

class PaymentObserver
{
    /**
     * Lien waiver auto-generation is intentionally disabled. Waivers are now
     * manually requested from subcontractors via the LienWaivers UI.
     */
    public function created(Payment $payment): void
    {
        //
    }

    public function updated(Payment $payment): void
    {
        //
    }
}

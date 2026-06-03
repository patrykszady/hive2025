<?php

namespace App\Observers;

use App\Models\Payment;
use App\Models\Transaction;

class PaymentObserver
{
    /**
     * Lien waiver auto-generation is intentionally disabled. Waivers are now
     * manually requested from subcontractors via the LienWaivers UI.
     */
    public function created(Payment $payment): void
    {
        $this->reindexLinkedTransaction($payment);
    }

    public function updated(Payment $payment): void
    {
        $this->reindexLinkedTransaction($payment);

        $original = $payment->getOriginal('transaction_id');
        if ($original && $original !== $payment->transaction_id) {
            $this->reindexTransactionId($original);
        }
    }

    public function deleted(Payment $payment): void
    {
        $this->reindexLinkedTransaction($payment);
    }

    private function reindexLinkedTransaction(Payment $payment): void
    {
        $this->reindexTransactionId($payment->transaction_id);
    }

    private function reindexTransactionId(?int $transactionId): void
    {
        if (! $transactionId) {
            return;
        }

        $transaction = Transaction::withoutGlobalScopes()->find($transactionId);
        $transaction?->searchable();
    }
}

<?php

namespace App\Http\Resources\Api\App;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A row in the app's "Transactions" card under Expenses — mirrors the web's
 * unmatched-transactions table (resources/views/livewire/expenses/
 * index.blade.php's Transactions card): amount, date, vendor (or a
 * payment_label badge — Cash/Zelle/Venmo/… — when there's no vendor), and
 * which bank/account it hit.
 *
 * @mixin \App\Models\Transaction
 */
class UnmatchedTransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Transaction $transaction */
        $transaction = $this->resource;

        return [
            'id' => $transaction->id,
            'amount' => (float) $transaction->amount,
            'date' => $transaction->transaction_date?->format('Y-m-d'),
            'vendor' => ($transaction->vendor && $transaction->vendor->id)
                ? ['id' => $transaction->vendor->id, 'name' => $transaction->vendor->business_name]
                : null,
            // Cash/Zelle/Venmo/PayPal/Cash App/Transfer — the badge the web
            // shows beside the description when there's no vendor
            // (Transaction::paymentLabel()); null for an ordinary vendor charge.
            'payment_label' => $transaction->paymentLabel(),
            'description' => $transaction->plaid_merchant_name ?: $transaction->plaid_merchant_description,
            'bank' => $transaction->bank_account?->bank?->name,
            'account' => $transaction->owner ?: $transaction->bank_account?->account_number,
        ];
    }
}

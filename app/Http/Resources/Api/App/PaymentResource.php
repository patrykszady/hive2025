<?php

namespace App\Http\Resources\Api\App;

use App\Models\Payment;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A client payment as the app's Payments tab shows it. With withDetail() it
 * adds the grouped sibling payments and matched bank transaction the detail
 * screen needs beyond the list row — see PaymentController::show() and the
 * web's livewire.payments.show (whose own "Payment Details" slot is empty,
 * so there is little more to mirror there than the header fields below).
 *
 * @mixin \App\Models\Payment
 */
class PaymentResource extends JsonResource
{
    protected bool $detail = false;

    public function withDetail(): static
    {
        $this->detail = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $project = $this->resource->project;
        $client = $project?->client;

        $row = [
            'id' => $this->id,
            'amount' => (float) $this->amount,
            'date' => $this->date?->toDateString(),
            'reference' => $this->reference,
            'note' => $this->note,
            'status' => $this->statusArray(),
            'project' => $project ? ['id' => $project->id, 'name' => $project->project_name] : null,
            'client' => $client ? ['id' => $client->id, 'name' => $client->name] : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'web_path' => '/payments/'.$this->id,
        ];

        if (! $this->detail) {
            return $row;
        }

        return $row + [
            'created_by_user_id' => $this->created_by_user_id,
            'check_id' => $this->check_id,
            // Every payment in this group (parent + children, or the
            // transaction-matched set) — Payment::payments always returns
            // an Eloquent collection, even for a standalone payment. A single
            // bank deposit can cover more than one client's checks, so each
            // row carries its OWN client — the web groups these rows by
            // client (payments/show.blade.php:66-76, "$group['client']").
            'grouped_payments' => $this->resource->payments->map(fn (Payment $payment) => [
                'id' => $payment->id,
                'amount' => (float) $payment->amount,
                'date' => $payment->date?->toDateString(),
                'reference' => $payment->reference,
                'project' => $payment->project ? ['id' => $payment->project->id, 'name' => $payment->project->project_name] : null,
                'client' => $payment->project?->client ? ['id' => $payment->project->client->id, 'name' => $payment->project->client->name] : null,
            ])->values()->all(),
            'transaction' => $this->transactionArray(),
        ];
    }

    /**
     * @return array{complete: bool, label: string, color: string}
     */
    private function statusArray(): array
    {
        $complete = $this->transaction_id !== null;

        return [
            'complete' => $complete,
            'label' => $complete ? 'Complete' : 'Missing',
            'color' => $complete ? 'green' : 'red',
        ];
    }

    /**
     * @return array{amount: float, date: ?string, bank: ?string, account: ?string, description: ?string}|null
     */
    private function transactionArray(): ?array
    {
        $transaction = $this->resource->transaction;

        if (! $transaction) {
            return null;
        }

        return [
            'amount' => (float) $transaction->amount,
            'date' => $transaction->transaction_date?->toDateString(),
            'bank' => $transaction->bank_account?->bank?->name,
            // The web's Payment Transaction table shows this as "Account" —
            // Capital One's card-member owner (Plaid's account_owner) when
            // set, else the bank account's own number (see
            // Transaction::cardNumbers()'s docblock for the same owner vs.
            // account-number precedence).
            'account' => $this->accountLabel($transaction),
            'description' => $transaction->plaid_merchant_description,
        ];
    }

    /**
     * The web's `$payment->transaction->owner ?? $payment->transaction->bank_account->account_number`,
     * as a string (owner is the numeric Plaid account_owner column; account_number is likewise numeric).
     */
    private function accountLabel(Transaction $transaction): ?string
    {
        if ($transaction->owner !== null) {
            return (string) $transaction->owner;
        }

        return $transaction->bank_account?->account_number !== null
            ? (string) $transaction->bank_account->account_number
            : null;
    }
}

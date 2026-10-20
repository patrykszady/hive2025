<?php

namespace App\Livewire\Transactions;

use App\Models\Bank;
use App\Models\TransactionBulkMatch;
use App\Models\Vendor;
use App\Models\VendorTransaction;
use App\Services\Transactions\VendorTransactionRuleService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

class VendorTransactionEditModal extends Component
{
    use AuthorizesRequests;

    public ?int $transaction_id = null;

    public string $vendor_id = '';

    public string $desc = '';

    public string $deposit_check = '';

    public string $amount_sign = '';

    public string $plaid_inst_id = '';

    public string $options = '';

    public array $depositCheckOptions = [];

    public function mount(): void
    {
        $this->depositCheckOptions = app(VendorTransactionRuleService::class)->depositCheckOptions();
    }

    #[Computed]
    public function vendors()
    {
        return Vendor::withoutGlobalScopes()
            ->select(['id', 'business_name'])
            ->orderBy('business_name', 'ASC')
            ->get();
    }

    #[Computed]
    public function banks()
    {
        return Bank::withoutGlobalScopes()
            ->whereNotNull('plaid_ins_id')
            ->orderBy('name', 'ASC')
            ->get()
            ->unique('plaid_ins_id')
            ->values();
    }

    #[On('editVendorTransaction')]
    public function editVendorTransaction(int $id): void
    {
        $this->authorize('viewAny', TransactionBulkMatch::class);

        $transaction = VendorTransaction::find($id);
        if (! $transaction) {
            return;
        }

        $this->transaction_id = $transaction->id;
        $this->vendor_id = $transaction->vendor_id === null ? '' : (string) $transaction->vendor_id;
        $this->desc = (string) $transaction->desc;
        $this->deposit_check = $transaction->deposit_check === null ? '' : (string) $transaction->deposit_check;
        $this->amount_sign = $transaction->amount_sign === null ? '' : (string) $transaction->amount_sign;
        $this->plaid_inst_id = (string) ($transaction->plaid_inst_id ?? '');
        $this->options = (string) ($transaction->options ?? '');

        $this->modal('vendor_transaction_edit_modal')->show();
    }

    public function save(): void
    {
        $this->authorize('viewAny', TransactionBulkMatch::class);

        if (! $this->transaction_id) {
            return;
        }

        $transaction = VendorTransaction::find($this->transaction_id);
        if (! $transaction) {
            return;
        }

        $rules = app(VendorTransactionRuleService::class);
        $payload = $rules->buildPayload([
            'vendor_id' => $this->vendor_id,
            'desc' => $this->desc,
            'deposit_check' => $this->deposit_check,
            'amount_sign' => $this->amount_sign,
            'plaid_inst_id' => $this->plaid_inst_id,
            'options' => $this->options,
        ]);

        if ($rules->exists($payload, $transaction->id)) {
            $this->addError('desc', 'A matching vendor transaction already exists.');

            return;
        }

        $rules->update($transaction, $payload);

        $this->modal('vendor_transaction_edit_modal')->close();

        $this->dispatch('refreshComponent')->to(VendorTransactionsPanel::class);
    }

    public function delete(): void
    {
        $this->authorize('viewAny', TransactionBulkMatch::class);

        if (! $this->transaction_id) {
            return;
        }

        $transaction = VendorTransaction::find($this->transaction_id);
        if (! $transaction) {
            return;
        }

        app(VendorTransactionRuleService::class)->delete($transaction);

        $this->transaction_id = null;
        $this->vendor_id = '';
        $this->desc = '';
        $this->deposit_check = '';
        $this->amount_sign = '';
        $this->plaid_inst_id = '';
        $this->options = '';

        $this->modal('vendor_transaction_edit_modal')->close();

        $this->dispatch('refreshComponent')->to(VendorTransactionsPanel::class);
    }

    public function render()
    {
        return view('livewire.transactions.vendor-transaction-edit-modal');
    }
}

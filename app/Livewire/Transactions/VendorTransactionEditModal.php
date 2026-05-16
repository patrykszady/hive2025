<?php

namespace App\Livewire\Transactions;

use App\Models\Bank;
use App\Models\Vendor;
use App\Models\VendorTransaction;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

class VendorTransactionEditModal extends Component
{
    public ?int $transaction_id = null;

    public string $vendor_id = '';

    public string $desc = '';

    public string $deposit_check = '';

    public string $amount_sign = '';

    public string $plaid_inst_id = '';

    public string $options = '';

    public array $depositCheckOptions = [
        '' => 'None',
        '1' => 'Deposit',
        '2' => 'Check Paid',
        '3' => 'Transfer/Zelle Out',
        '4' => 'Cash Withdrawal',
    ];

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
        if (! $this->transaction_id) {
            return;
        }

        $transaction = VendorTransaction::find($this->transaction_id);
        if (! $transaction) {
            return;
        }

        $payload = [
            'vendor_id' => $this->vendor_id === '' ? null : (int) $this->vendor_id,
            'desc' => trim($this->desc),
            'deposit_check' => $this->deposit_check === '' ? null : (int) $this->deposit_check,
            'amount_sign' => $this->amount_sign === '' ? null : (int) $this->amount_sign,
            'plaid_inst_id' => $this->plaid_inst_id === '' ? null : trim($this->plaid_inst_id),
            'options' => $this->normalizedOptions($this->options),
        ];

        if ($this->vendorTransactionExists($payload, $transaction->id)) {
            $this->addError('desc', 'A matching vendor transaction already exists.');

            return;
        }

        $transaction->vendor_id = $payload['vendor_id'];
        $transaction->desc = $payload['desc'];
        $transaction->deposit_check = $payload['deposit_check'];
        $transaction->amount_sign = $payload['amount_sign'];
        $transaction->plaid_inst_id = $payload['plaid_inst_id'];
        $transaction->options = $payload['options'];
        $transaction->save();

        $this->modal('vendor_transaction_edit_modal')->close();

        $this->dispatch('refreshComponent')->to(VendorTransactionsPanel::class);
    }

    public function delete(): void
    {
        if (! $this->transaction_id) {
            return;
        }

        $transaction = VendorTransaction::find($this->transaction_id);
        if (! $transaction) {
            return;
        }

        $transaction->delete();

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

    protected function normalizedOptions(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return json_encode('/i');
        }

        return json_encode(trim($value).'/i');
    }

    protected function vendorTransactionExists(array $payload, int $ignoreId): bool
    {
        return VendorTransaction::query()
            ->where('id', '!=', $ignoreId)
            ->where('desc', $payload['desc'])
            ->where('options', $payload['options'])
            ->where(fn ($query) => $payload['vendor_id'] === null
                ? $query->whereNull('vendor_id')
                : $query->where('vendor_id', $payload['vendor_id']))
            ->where(fn ($query) => $payload['deposit_check'] === null
                ? $query->whereNull('deposit_check')
                : $query->where('deposit_check', $payload['deposit_check']))
            ->where(fn ($query) => $payload['amount_sign'] === null
                ? $query->whereNull('amount_sign')
                : $query->where('amount_sign', $payload['amount_sign']))
            ->where(fn ($query) => $payload['plaid_inst_id'] === null
                ? $query->whereNull('plaid_inst_id')
                : $query->where('plaid_inst_id', $payload['plaid_inst_id']))
            ->exists();
    }

    public function render()
    {
        return view('livewire.transactions.vendor-transaction-edit-modal');
    }
}

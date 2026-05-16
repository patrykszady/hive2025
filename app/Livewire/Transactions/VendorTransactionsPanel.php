<?php

namespace App\Livewire\Transactions;

use App\Models\Bank;
use App\Models\Vendor;
use App\Models\VendorTransaction;
use Livewire\Attributes\On;
use Livewire\Component;

class VendorTransactionsPanel extends Component
{
    public array $vendor_transactions = [];
    public array $new_vendor_transaction = [];
    public array $depositCheckOptions = [
        '' => 'None',
        '1' => 'Deposit',
        '2' => 'Check Paid',
        '3' => 'Transfer/Zelle Out',
        '4' => 'Cash Withdrawal',
    ];

    public $vendors = [];
    public $banks = [];

    public function mount(): void
    {
        $this->resetNewVendorTransaction();
        $this->vendors = Vendor::withoutGlobalScopes()
            ->select(['id', 'business_name'])
            ->orderBy('business_name', 'ASC')
            ->get();
        $this->banks = Bank::withoutGlobalScopes()
            ->whereNotNull('plaid_ins_id')
            ->orderBy('name', 'ASC')
            ->get()
            ->unique('plaid_ins_id')
            ->values();

        $this->loadVendorTransactions();
    }

    public function updateVendorTransaction($id, $field, $value): void
    {
        // Per-row inline edits replaced by VendorTransactionEditModal.
        // Method retained for backward compatibility; intentionally a no-op.
    }

    #[On('refreshComponent')]
    public function refreshFromModal(): void
    {
        $this->loadVendorTransactions();
    }

    public function createVendorTransaction(): void
    {
        $validated = $this->validate([
            'new_vendor_transaction.desc' => 'required|string',
            'new_vendor_transaction.vendor_id' => 'nullable|integer',
            'new_vendor_transaction.deposit_check' => 'nullable|in:1,2,3,4',
            'new_vendor_transaction.amount_sign' => 'nullable|in:1,2',
            'new_vendor_transaction.plaid_inst_id' => 'nullable|string',
            'new_vendor_transaction.options' => 'nullable|string',
        ]);

        $payload = [
            'vendor_id' => $this->normalizeVendorTransactionValue('vendor_id', $validated['new_vendor_transaction']['vendor_id'] ?? ''),
            'deposit_check' => $this->normalizeVendorTransactionValue('deposit_check', $validated['new_vendor_transaction']['deposit_check'] ?? ''),
            'amount_sign' => $this->normalizeVendorTransactionValue('amount_sign', $validated['new_vendor_transaction']['amount_sign'] ?? ''),
            'plaid_inst_id' => ($validated['new_vendor_transaction']['plaid_inst_id'] ?? '') !== ''
                ? trim((string) $validated['new_vendor_transaction']['plaid_inst_id'])
                : null,
            'desc' => trim((string) $validated['new_vendor_transaction']['desc']),
            'options' => $this->formatVendorTransactionOptions($validated['new_vendor_transaction']['options'] ?? null),
        ];

        if ($this->vendorTransactionExists($payload)) {
            $this->addError('new_vendor_transaction.desc', 'A matching vendor transaction already exists.');

            return;
        }

        VendorTransaction::create($payload);

        $this->resetNewVendorTransaction();
        $this->loadVendorTransactions();
    }

    protected function loadVendorTransactions(): void
    {
        $transactions = VendorTransaction::with(['vendor', 'bank'])
            ->select(['id', 'vendor_id', 'deposit_check', 'amount_sign', 'plaid_inst_id', 'desc', 'options'])
            ->get()
            ->sortBy(function (VendorTransaction $transaction): string {
                $vendorName = strtolower($transaction->vendor?->business_name ?? '');

                return sprintf(
                    '%d|%s|%010d',
                    $transaction->vendor?->business_name === null ? 1 : 0,
                    $vendorName,
                    $transaction->id,
                );
            })
            ->values();

        $this->vendor_transactions = $transactions
            ->map(function (VendorTransaction $transaction): array {
                return [
                    'id' => $transaction->id,
                    'vendor' => $transaction->vendor ? [
                        'id' => $transaction->vendor->id,
                        'business_name' => $transaction->vendor->business_name,
                    ] : null,
                    'bank' => $transaction->bank ? [
                        'id' => $transaction->bank->id,
                        'name' => $transaction->bank->name,
                        'plaid_ins_id' => $transaction->bank->plaid_ins_id,
                    ] : null,
                    'desc' => $transaction->desc,
                    'deposit_check' => $transaction->deposit_check,
                    'deposit_check_label' => $this->depositCheckLabel($transaction->deposit_check),
                    'amount_sign' => $transaction->amount_sign,
                    'plaid_inst_id' => $transaction->plaid_inst_id,
                    'options' => $transaction->options,
                ];
            })
            ->all();
    }

    protected function resetNewVendorTransaction(): void
    {
        $this->new_vendor_transaction = [
            'vendor_id' => '',
            'desc' => '',
            'deposit_check' => '3',
            'amount_sign' => '',
            'plaid_inst_id' => '',
            'options' => '',
        ];
    }

    protected function depositCheckLabel(?int $value): string
    {
        return $this->depositCheckOptions[(string) $value] ?? 'Unknown';
    }

    protected function formatVendorTransactionOptions(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return json_encode('/i');
        }

        return json_encode(trim($value).'/i');
    }

    protected function normalizeVendorTransactionValue(string $field, mixed $value): mixed
    {
        if ($value === '') {
            return null;
        }

        if (in_array($field, ['vendor_id', 'deposit_check', 'amount_sign'], true)) {
            return (int) $value;
        }

        return $value;
    }

    protected function vendorTransactionExists(array $payload): bool
    {
        return VendorTransaction::query()
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

    public function placeholder()
    {
        return view('livewire.transactions.vendor-transactions-panel-placeholder');
    }

    public function render()
    {
        return view('livewire.transactions.vendor-transactions-panel');
    }
}
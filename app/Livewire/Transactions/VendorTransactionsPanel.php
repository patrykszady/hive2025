<?php

namespace App\Livewire\Transactions;

use App\Models\Bank;
use App\Models\Vendor;
use App\Models\VendorTransaction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * "New Vendor Transaction" form + the paginated, searchable list of match
 * rules. Rows, vendors and banks are computed per request: the old version
 * kept all 500+ rows and 800+ vendors as public arrays, so every interaction
 * shipped them to the browser and back, and the vendor picker printed the
 * whole list as options.
 */
class VendorTransactionsPanel extends Component
{
    use WithPagination;

    public const PER_PAGE = 25;

    public const VENDOR_OPTION_LIMIT = 25;

    public array $new_vendor_transaction = [];

    public array $depositCheckOptions = [
        '' => 'None',
        '1' => 'Deposit',
        '2' => 'Check Paid',
        '3' => 'Transfer/Zelle Out',
        '4' => 'Cash Withdrawal',
    ];

    /** Filters the table by vendor name or description. */
    public string $search = '';

    /** The form's vendor picker: what was typed, and how far it has scrolled. */
    public string $vendorSearch = '';

    public int $vendorLimit = self::VENDOR_OPTION_LIMIT;

    public function mount(): void
    {
        $this->resetNewVendorTransaction();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /** The picker's search box calls this (debounced); a new term restarts paging from the top. */
    public function searchVendors(string $term): void
    {
        $this->vendorSearch = $term;
        $this->vendorLimit = self::VENDOR_OPTION_LIMIT;
        unset($this->vendorOptions);
    }

    public function loadMoreVendorOptions(): void
    {
        $this->vendorLimit += self::VENDOR_OPTION_LIMIT;
        unset($this->vendorOptions);
    }

    /** True while more vendors exist beyond what the open dropdown has shown. */
    public function hasMoreVendorOptions(): bool
    {
        return $this->vendorOptions->count() >= $this->vendorLimit;
    }

    /**
     * One scroll-page of vendors matching the search (or the alphabetical
     * head of the list), plus the selected vendor so the picker can label it.
     */
    #[Computed]
    public function vendorOptions(): Collection
    {
        $term = trim($this->vendorSearch);
        $columns = ['id', 'business_name', 'city', 'state'];

        $options = Vendor::withoutGlobalScopes()
            ->select($columns)
            ->when($term !== '', fn ($query) => $query->where('business_name', 'like', "%{$term}%"))
            ->orderBy('business_name')
            ->limit($this->vendorLimit)
            ->get();

        $selected = $this->new_vendor_transaction['vendor_id'] ?? '';
        if (is_numeric($selected) && (int) $selected > 0 && ! $options->contains('id', (int) $selected)) {
            $vendor = Vendor::withoutGlobalScopes()->select($columns)->find((int) $selected);
            if ($vendor) {
                $options->prepend($vendor);
            }
        }

        return $options;
    }

    #[Computed]
    public function banks(): Collection
    {
        return Bank::withoutGlobalScopes()
            ->whereNotNull('plaid_ins_id')
            ->orderBy('name', 'ASC')
            ->get()
            ->unique('plaid_ins_id')
            ->values();
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

    /** The rows are computed per request, so a reload is just dropping the cache. */
    protected function loadVendorTransactions(): void
    {
        unset($this->vendor_transactions);
    }

    /**
     * The match rules, a page at a time: named vendors first (alphabetical,
     * case-blind), then the vendor-less deposit/check/transfer rules — the
     * same order the page always had, now done by the database.
     */
    #[Computed]
    public function vendor_transactions(): LengthAwarePaginator
    {
        $term = trim($this->search);

        return VendorTransaction::query()
            ->select([
                'vendor_transactions.id',
                'vendor_transactions.vendor_id',
                'vendor_transactions.deposit_check',
                'vendor_transactions.amount_sign',
                'vendor_transactions.plaid_inst_id',
                'vendor_transactions.desc',
                'vendor_transactions.options',
            ])
            ->leftJoin('vendors', 'vendors.id', '=', 'vendor_transactions.vendor_id')
            ->with(['vendor', 'bank'])
            ->when($term !== '', fn ($query) => $query->where(fn ($where) => $where
                ->where('vendor_transactions.desc', 'like', "%{$term}%")
                ->orWhere('vendors.business_name', 'like', "%{$term}%")))
            ->orderByRaw('vendors.business_name IS NULL')
            ->orderByRaw('LOWER(vendors.business_name)')
            ->orderBy('vendor_transactions.id')
            ->paginate(self::PER_PAGE)
            ->through(fn (VendorTransaction $transaction): array => [
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
            ]);
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

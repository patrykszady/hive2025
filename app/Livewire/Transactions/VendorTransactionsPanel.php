<?php

namespace App\Livewire\Transactions;

use App\Models\Bank;
use App\Services\Transactions\VendorTransactionRuleService;
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


    public array $new_vendor_transaction = [];

    public array $depositCheckOptions = [];

    /** Filters the table by vendor name or description. */
    public string $search = '';


    public function mount(): void
    {
        $this->depositCheckOptions = app(VendorTransactionRuleService::class)->depositCheckOptions();
        $this->resetNewVendorTransaction();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
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

        $rules = app(VendorTransactionRuleService::class);
        $payload = $rules->buildPayload($validated['new_vendor_transaction']);

        if ($rules->exists($payload)) {
            $this->addError('new_vendor_transaction.desc', 'A matching vendor transaction already exists.');

            return;
        }

        $rules->create($payload);

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
        return app(VendorTransactionRuleService::class)->paginate($this->search, self::PER_PAGE);
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

    public function placeholder()
    {
        return view('livewire.transactions.vendor-transactions-panel-placeholder');
    }

    public function render()
    {
        return view('livewire.transactions.vendor-transactions-panel');
    }
}

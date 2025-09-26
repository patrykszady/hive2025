<?php

namespace App\Livewire\VendorDocs;

use App\Models\Bank;
use App\Models\Check;
use App\Models\Transaction;
use Carbon\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Component;

class AuditIndex extends Component
{
    // Computed list of banks; selection tracked as array of bank IDs
    public array $selected_bank_ids = [];
    public $start_date = '';
    public $end_date = '';
    public $type = '';

    protected function rules()
    {
        return [
            'selected_bank_ids' => ['required','array','min:1'],
            'selected_bank_ids.*' => ['integer','distinct'],
            'start_date' => 'required|date',
            'end_date' => 'required|date',
            'type' => 'required', //workers or liablity | dropfown
        ];
    }

    public function updated($field, $value)
    {
        if ($field === 'start_date' && !empty($value) && empty($this->end_date)) {
            try {
                $this->end_date = Carbon::createFromFormat('Y-m-d', $value)
                    ->addYear()
                    ->format('Y-m-d');
            } catch (\Throwable $e) {
                // ignore formatting issues; validation will handle
            }
        }
        $this->validateOnly($field);
    }

    #[Computed]
    public function banks(): array
    {
        // [bankId => ['name' => string, 'accounts' => array<int>]]
        return Bank::whereNotNull('plaid_access_token')
            ->whereNotNull('plaid_ins_id')
            ->get()
            ->keyBy('id')
            ->map(function (Bank $bank) {
                $accountIds = $bank->institution_accounts()
                    ->whereIn('bank_accounts.type', ['Checking', 'Savings'])
                    ->pluck('bank_accounts.id')
                    ->all();

                return [
                    'name' => $bank->name,
                    'accounts' => $accountIds,
                ];
            })
            ->filter(fn (array $b) => !empty($b['accounts']))
            ->toArray();
    }

    public function audit_submit()
    {
        $this->validate();
        // Collect selected bank account IDs from chosen banks
        $selected = collect($this->selected_bank_ids)->map(fn ($id) => (int)$id)->all();
        $bank_account_ids = collect($this->banks)
            ->only($selected)
            ->flatMap(fn ($b) => $b['accounts'] ?? [])
            ->unique()
            ->values()
            ->all();

        return redirect()->route('vendor_docs.audit', [
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'bank_account_ids' => $bank_account_ids,
            'audit_type' => $this->type,
        ]);
    }

    public function render()
    {
        return view('livewire.vendor-docs.audit-index');
    }
}

<?php

namespace App\Livewire\Sheets;

use App\Models\Bank;
use App\Models\Sheet;
use App\Models\BankAccount;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Illuminate\Support\Carbon;

class SheetsIndex extends Component
{
    use AuthorizesRequests;

    public $start_date = '2024-01-01';
    public $end_date = '2024-12-31';
    public $cash = 'include';

    public $banks = []; // Keep track of selected banks
    protected function rules()
    {
        return [
            'banks' => [
                'required',
                'array',
                function ($attribute, $value, $fail) {
                    $hasSelected = false;
                    foreach ((array) $value as $bank) {
                        if (!empty($bank['checked'])) {
                            $hasSelected = true;
                            break;
                        }
                    }
                    if (! $hasSelected) {
                        $fail('Please select at least one bank account.');
                    }
                },
            ],
            'banks.*.checked' => 'boolean',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
        ];
    }

    public function mount()
    {
        // Set default dates if not provided
        if (empty($this->start_date)) {
            $this->start_date = date('Y-m-d', strtotime('-1 year')); // Today minus 1 year
        }
        
        if (empty($this->end_date)) {
            $this->end_date = date('Y-m-d'); // Today's date
        }

        // Initialize selection map if empty (keep UI state separate from computed data)
        if (empty($this->banks)) {
            foreach ($this->availableBanks() as $bank) {
                $this->banks[$bank->id]['checked'] = false;
            }
        }
    }

    public function updated($propertyName)
    {
        if ($propertyName === 'start_date' && !empty($this->start_date)) {
            try {
                $start = Carbon::parse($this->start_date);
                // Set end date to one year minus one day from start (e.g., 2024-01-01 -> 2024-12-31)
                $this->end_date = $start->copy()->addYear()->subDay()->format('Y-m-d');
            } catch (\Throwable $e) {
                // Ignore parse issues; validation will handle errors
            }
        }
        $this->validateOnly($propertyName);
    }

    #[Computed]
    public function availableBanks()
    {
        return Bank::whereNotNull('plaid_access_token')
            ->with(['accounts' => function ($query) {
                $query->whereIn('type', ['Checking', 'Savings']);
            }])
            ->whereHas('accounts', function ($query) {
                return $query->whereIn('type', ['Checking', 'Savings']);
            })
            ->get()
            ->keyBy('id');
    }

    public function run()
    {
        $this->validate();
        // $bank_accounts = collect();

        // foreach ($this->availableBanks() as $bank) {
        //     if (!empty($this->banks[$bank->id]['checked'])) {
        //         $bank_accounts->put($bank->id, $bank->accounts->pluck('id'));
        //     }
        // }
        // $bank_account_ids = $bank_accounts->flatten()->toArray();
        $bank_account_ids = BankAccount::all()->pluck('id')->toArray();

        //dispatch to SheetShow w/ dates and bank_accounts
        return redirect()->route('sheets.show', [
            'bank_account_ids' => $bank_account_ids,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'cash' => $this->cash,
        ]);

        // $this->dispatch('sheet_info')->to(SheetShow::class);
    }

    #[Title('Sheets')]
    public function render()
    {
        $this->authorize('viewAny', Sheet::class);

        return view('livewire.sheets.index');
    }
}

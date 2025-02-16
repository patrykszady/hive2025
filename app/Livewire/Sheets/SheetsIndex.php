<?php

namespace App\Livewire\Sheets;

// use Livewire\Component\Sheets\SheetShow;
use App\Models\Bank;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Title;
use Livewire\Component;

class SheetsIndex extends Component
{
    use AuthorizesRequests;

    public $start_date = '';

    public $end_date = '';

    public $banks = [];

    protected function rules()
    {
        return [
            'banks.*.checked' => 'nullable', // multiple checkbox, at least one required
            'start_date' => 'required',
            'end_date' => 'required',
        ];
    }

    public function mount()
    {
        $this->banks =
            Bank::whereNotNull('plaid_access_token')
                ->with(['accounts'])
                ->whereHas('accounts', function ($query) {
                    return $query->whereIn('type', ['Checking', 'Savings']);
                })
                ->get()
                ->each(function ($item, $key) {
                    $item->checked = false;
                })
                ->keyBy('id');
    }

    public function run()
    {
        $this->validate();
        $bank_accounts = collect();

        foreach ($this->banks->where('checked', true) as $bank) {
            $bank_accounts->put($bank->id, $bank->accounts->pluck('id'));
        }
        $bank_account_ids = $bank_accounts->flatten()->toArray();

        //dispatch to SheetShow w/ dates and bank_accounts
        return redirect()->route('sheets.show', [
            'bank_account_ids' => $bank_account_ids,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
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

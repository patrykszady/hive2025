<?php

namespace App\Livewire\VendorDocs;

use App\Models\Bank;
use App\Models\Check;
use App\Models\Transaction;
use Livewire\Component;

class AuditIndex extends Component
{
    public $banks = [];

    // public $audit = [];
    public $end_date = '';

    public $type = '';

    protected function rules()
    {
        return [
            // 'banks' => 'required',
            'banks.*.checked' => 'nullable', // multiple checkbox
            'end_date' => 'required',
            'type' => 'required', //workers or liablity | dropfown
        ];
    }

    public function updated($field, $value)
    {
        // dd($field);
        $this->validateOnly($field);
    }

    public function mount()
    {
        // $this->authorize('viewAny', Expense::class);

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

    public function audit_submit()
    {
        // $this->authorize('update', $this->expense);
        $this->validate();
        // $this->redirect(AuditShow::class, audit_type: 'workers');
        // return redirect(view('livewire.vendor-docs.audit-show'));
        // dd("audit audit_submit");
        // $this->dispatch('audit')->to(AuditShow::class);
        $banks = $this->banks->where('checked', true);
        $bank_account_ids = $banks->pluck('accounts')->flatten()->pluck('id')->toArray();

        // $vendor_checks =
        //     Check::whereBetween('date', [$start_date, $end_date])
        //         ->with(['vendor'])
        //         ->whereIn('bank_account_id', $bank_account_ids)
        //         ->whereNot('check_type', 'Cash')
        //         ->whereNotNull('vendor_id')
        //         ->get()
        //         ->groupBy('vendor_id');

        // dd($vendor_checks);

        // $checks =
        // Check::whereBetween('date', [$start_date, $end_date])
        //     ->with(['vendor', 'user'])
        //     ->whereIn('bank_account_id', $bank_account_ids)
        //     ->whereNot('check_type', 'Cash')
        //     ->whereNotNull('user_id')
        //     ->get()
        //     ->groupBy('user_id')
        //     ->take(5);

        return redirect()->route('vendor_docs.audit', [
            'end_date' => $this->end_date,
            'bank_account_ids' => $bank_account_ids,
            'audit_type' => $this->type,
        ]);

        //emitTo AuditShow audit method

        // dd($end_date);

        // return redirect(view('livewire.vendor-docs.audit-show'));

        // $transactions =
        //     Transaction::
        //         whereIn('bank_account_id', $bank_accounts)
        //         ->whereNotNull('check_number')
        //         ->whereHas('check', function ($query) {
        //             return $query->whereNull('user_id')->groupBy('vendor_id');
        //             })
        //         ->where('check_number', '!=', '2020202')
        //         ->whereBetween('posted_date', [
        //             $start_date, $end_date ])
        //         ->with(['check'])
        //         ->get();

        // dd($transactions->first());
    }

    public function render()
    {
        // vendor-docs.audit-index
        return view('livewire.vendor-docs.audit-index');
    }
}

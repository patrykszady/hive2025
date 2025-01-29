<?php

namespace App\Livewire\Transactions;

use App\Models\BankAccount;
use App\Models\Expense;
use App\Models\Transaction;
use App\Models\Vendor;
use App\Models\VendorTransaction;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Title;
use Livewire\Component;

class MatchVendor extends Component
{
    use AuthorizesRequests;

    public $vendors = [];

    public $expense_receipt_merchants = [];

    public $merchant_names = [];

    public $match_merchant_names = [];

    public $match_expense_merchant_names = [];

    public $match_vendor_names = [];

    public $view_text = [
        'card_title' => 'Save Transactions/Vendor',
        'button_text' => 'Sync Transactions & Vendors',
        'form_submit' => 'store',
    ];

    protected function rules()
    {
        return [
            'match_merchant_names.*.match_desc' => 'required',
            'match_merchant_names.*.vendor_id' => 'required',
            'match_expense_merchant_names.*.match_desc' => 'required',
            'match_expense_merchant_names.*.vendor_id' => 'required',
        ];
    }

    public function mount()
    {
        $this->vendors = Vendor::withoutGlobalScopes()->orderBy('business_name', 'ASC')->get();
        $this->expense_receipt_merchants =
            Expense::withoutGlobalScopes()
                ->with('receipts')
                ->whereNull('deleted_at')
                ->where('vendor_id', 0)
                ->get()
                ->each(function ($expense, $key) {
                    $receipt = $expense->receipts()->latest()->first();
                    if (isset($receipt->receipt_items->merchant_name)) {
                        $expense->merchant_name = $receipt->receipt_items->merchant_name;
                    }
                })
                ->groupBy('merchant_name')
                ->toBase();
        // dd($this->expense_receipt_merchants);
        $this->match_vendor_names = Transaction::transactionsSinVendor()->get()->groupBy('plaid_merchant_name')->values()->toArray();
    }

    public function updated($field)
    {
        $this->validateOnly($field);
    }

    public function store_expense_vendors()
    {
        // $this->authorize('create', Expense::class);
        $this->validate();

        foreach ($this->match_expense_merchant_names as $key => $vendor_match) {
            if ($vendor_match['vendor_id'] == 'NEW') {
                //new Retail Vendor
                $vendor = Vendor::create([
                    'business_type' => 'Retail',
                    'business_name' => $vendor_match['match_desc'],
                ]);

                $vendor_id = $vendor->id;
                foreach ($this->expense_receipt_merchants[$vendor_match['match_desc']] as $expense) {
                    $expense->vendor_id = $vendor_id;
                    $expense->save();
                }
            } else {
                $deposit_check = null;
                $vendor_id = $vendor_match['vendor_id'];

                $institution_id = null;
                $options = json_encode('/i');

                $vendor_transaction = VendorTransaction::create([
                    'vendor_id' => $vendor_id,
                    'deposit_check' => $deposit_check,
                    'desc' => $vendor_match['match_desc'],
                    'plaid_inst_id' => $institution_id,
                    'options' => $options,
                ]);
            }

            //USED IN MULTIPLE OF PLACES TransactionController@add_vendor_to_transactions, ExpesnesForm@createExpenseFromTransaction
            //add if vendor is not part of the currently logged in vendor
            if (! in_array($vendor_id, $this->vendors->pluck('id')->toArray())) {
                auth()->user()->vendor->vendors()->attach($vendor_id);
            }
        }

        //add vendor to expense ...
        //6-8-2022 run in a queue?
        app(\App\Http\Controllers\TransactionController::class)->add_transaction_to_expenses_sin_vendor();

        return redirect(route('transactions.match_vendor'));
    }

    public function store()
    {
        $this->validate();
        // $this->authorize('create', Expense::class);

        foreach ($this->match_merchant_names as $key => $vendor_match) {
            if ($vendor_match['vendor_id'] == 'NEW') {
                //new Retail Vendor
                $vendor = Vendor::create([
                    'business_type' => 'Retail',
                    'business_name' => $vendor_match['match_desc'],
                ]);

                $vendor_id = $vendor->id;
            } else {
                if ($vendor_match['vendor_id'] == 'DEPOSIT') {
                    $deposit_check = 1;
                    $vendor_id = null;
                } elseif ($vendor_match['vendor_id'] == 'CHECK') {
                    $deposit_check = 2;
                    $vendor_id = null;
                } elseif ($vendor_match['vendor_id'] == 'TRANSFER') {
                    $deposit_check = 3;
                    $vendor_id = null;
                } elseif ($vendor_match['vendor_id'] == 'CASH') {
                    $deposit_check = 4;
                    $vendor_id = null;
                } else {
                    $deposit_check = null;
                    $vendor_id = $vendor_match['vendor_id'];
                }

                if (isset($vendor_match['bank_specific'])) {
                    $institution_id = $this->merchant_names->values()[$key][0]['bank_account']['bank']['plaid_ins_id'];
                } else {
                    $institution_id = null;
                }

                if (isset($vendor_match['options'])) {
                    $options = json_encode($vendor_match['options'].'/i');
                } else {
                    $options = json_encode('/i');
                }

                $vendor_transaction = VendorTransaction::create([
                    'vendor_id' => $vendor_id,
                    'deposit_check' => $deposit_check,
                    'desc' => str_replace('*', "\*", $vendor_match['match_desc']),
                    'plaid_inst_id' => $institution_id,
                    'options' => $options,
                ]);
            }

            //USED IN MULTIPLE OF PLACES TransactionController@add_vendor_to_transactions, ExpesnesForm@createExpenseFromTransaction
            //add if vendor is not part of the currently logged in vendor
            if (! in_array($vendor_id, $this->vendors->pluck('id')->toArray())) {
                auth()->user()->vendor->vendors()->attach($vendor_id);
            }
        }

        //add vendor to transaction ...

        //6-8-2022 run in a queue?
        app(\App\Http\Controllers\TransactionController::class)->add_vendor_to_transactions();
        app(\App\Http\Controllers\TransactionController::class)->add_check_deposit_to_transactions();

        return redirect(route('transactions.match_vendor'));
    }

    #[Title('Match Transaction/Vendor')]
    public function render()
    {
        $this->authorize('viewAny', TransactionBulkMatch::class);

        $transaction_bank_accounts = BankAccount::withoutGlobalScopes()->whereNull('deleted_at')->pluck('id')->toArray();
        $this->merchant_names =
            Transaction::transactionsSinVendor()
                ->whereIn('bank_account_id', $transaction_bank_accounts)
                ->with([
                    'bank_account' => fn ($query) => $query->withoutGlobalScopes()->with([
                        'bank' => fn ($query) => $query->withoutGlobalScopes()->with([
                            'vendor' => fn ($query) => $query->withoutGlobalScopes(),
                        ]),
                    ]),
                ])
                ->get()
                ->groupBy('plaid_merchant_description')
                ->toBase();

        return view('livewire.transactions.match-vendor', [
            'merchant_names' => $this->merchant_names,
        ]);
    }
}

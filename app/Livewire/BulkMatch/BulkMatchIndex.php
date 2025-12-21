<?php

namespace App\Livewire\BulkMatch;

// use App\Models\Expense;
// use App\Models\Transaction;
use App\Models\Distribution;
use App\Models\TransactionBulkMatch;
use App\Models\Vendor;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

use Livewire\Attributes\Title;
use Livewire\Component;

class BulkMatchIndex extends Component
{
    use AuthorizesRequests;

    public $distributions = [];

    public $bulk_matches = [];

    public $vendor = null;

    public $vendor_id = null;

    public $vendor_amount_group = [];

    public $vendor_transactions = null;

    public $vendor_expenses = null;

    protected $listeners = ['refreshComponent' => '$refresh', 'manualMatch', 'bulkSplits', 'addSplit', 'removeSplit'];

    public function mount()
    {
        $this->authorize('viewAny', TransactionBulkMatch::class);

        $this->distributions = Distribution::all();
        //exclude Vendors that dont/have receipt accounts
        // $exclude_vendors = Vendor::whereDoesntHave('receipt_accounts')->pluck('id')->toArray();
        // dd($exclude_vendors);
        $vendor_ids_to_ignore =
            Vendor::
                whereHas('receipt_account')
                ->orderBy('business_name')
                ->pluck('id')
                ->toArray();

        $this->bulk_matches =
            TransactionBulkMatch::with(['vendor', 'distribution'])
                ->whereNotIn('vendor_id', $vendor_ids_to_ignore)
                ->get()
                ->sortBy(function ($item, $key) {
                    return $item->vendor->business_name;
                });

            // Event::whereHas('participants', function ($query) {
            //     return $query->where('IDUser', '=', 1);
            //     })->get();
        // dd($this->bulk_matches);
    }

    public function updated($field, $value)
    {
        // if(substr($field, 0, 19) == 'vendor_amount_group'){
        //     //toggle checkmark
        //     $vendor_transactions_key = preg_replace("/[^0-9]/", '', $field);

        //     if($this->vendor_amount_group[$vendor_transactions_key]['checkbox'] == false){
        //         //remove from vendor_amount_group
        //         unset($this->vendor_amount_group[$vendor_transactions_key]);
        //     }
        // }

        $this->validateOnly($field);
    }

    // public function updatedVendorId($value)
    // {
    //     $this->vendor = Vendor::findOrFail($value);
    // }

    // public function updatedAnyAmount($value)
    // {

    //     $this->amount = NULL;
    // }

    // public function updatedAmount($value)
    // {
    //     if(empty($value)){

    //         $this->amount = NULL;
    //     }
    // }

    public function manualMatch()
    {
        if (empty($this->distribution_id)) {
            //Does Not reset $this->payment_projects.*.AMOUNT
            $this->addError('distribution_id', 'Distribution is required.');
        } else {
            $manual_transactions = [];
            foreach ($this->vendor_transactions as $key => $transaction) {
                if (in_array($key, array_keys($this->vendor_amount_group))) {
                    array_push($manual_transactions, $transaction);
                }
            }

            foreach ($manual_transactions as $amount_transactions) {
                foreach ($amount_transactions as $amount_transaction) {
                    $transaction = Transaction::findOrFail($amount_transaction['id']);

                    //create expene from transaction
                    $expense = Expense::create([
                        'amount' => $transaction->amount,
                        'date' => $transaction->transaction_date,
                        'project_id' => null,
                        'distribution_id' => $this->distribution_id,
                        'vendor_id' => $transaction->vendor_id,
                        'belongs_to_vendor_id' => auth()->user()->vendor->id,
                        'created_by_user_id' => 0,
                    ]);

                    $transaction->expense_id = $expense->id;
                    $transaction->save();
                }
            }

            //refresh component
            $this->dispatch('refreshComponent');
            $this->vendor_amount_group = [];
            $this->vendor_transactions = null;
            // $this->distribution_id = NULL;
            //send notification
        }
    }

    public function store()
    {
        dd('here in store of BulkMatch');
        // app('App\Http\Controllers\TransactionController')->transaction_vendor_bulk_match();
    }

    #[Title('Bulk Transactions')]
    public function render()
    {
        $this->authorize('viewAny', TransactionBulkMatch::class);

        if ($this->vendor) {
            //transactions groupBy amount

            //02-24-2024 sortBy/orderBy count of transactions per amount/groupBy
            $this->vendor_transactions =
                $this->vendor->transactions()
                    ->whereDoesntHave('expense')
                    ->whereDoesntHave('check')
                    ->orderBy('amount', 'DESC')
                    ->get()
                    ->groupBy('amount')
                    ->values()
                //converts to array?
                    ->toBase();
            // dd($this->vendor_transactions);

            $this->vendor_expenses =
                $this->vendor->expenses()
                    ->whereDoesntHave('splits')
                    ->where('project_id', '0')
                    ->whereNull('distribution_id')
                    ->orderBy('amount', 'DESC')
                    ->get()
                    ->groupBy('amount')
                    ->toBase();
            // dd($this->vendor_expenses);
        }

        return view('livewire.transactions.bulk-match', [
            // 'transactions' => $transactions,
            // 'vendors' => $vendors,
        ]);
    }
}

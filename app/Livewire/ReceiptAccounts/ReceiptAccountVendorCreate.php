<?php

namespace App\Livewire\ReceiptAccounts;

// use App\Livewire\Forms\BulkMatchForm;
use App\Models\Distribution;
use App\Models\ReceiptAccount;
use App\Models\Expense;
use App\Models\Transaction;
use App\Models\TransactionBulkMatch;
use App\Models\Vendor;
use App\Http\Controllers\TransactionController;

use App\Jobs\TransactionVendorBulkMatchJob;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

use Flux;
use Livewire\Attributes\Computed;
use Livewire\Component;

class ReceiptAccountVendorCreate extends Component
{
    use AuthorizesRequests;
    protected $listeners = ['refreshComponent' => '$refresh', 'editReceiptVendor'];

    // public BulkMatchForm $form;
    public $distributions = []; //coming from ReceiptAccountsIndex

    public $distribution_id = null;

    public $transactions_bulk_matches = []; //collection

    public Vendor $vendor;

    //$this->form->setMatch($match);

    protected function rules()
    {

        return [
            'vendor.logged_in' => 'nullable',
            'distribution_id' => 'required',
            'transactions_bulk_matches.*.options.amount_type' => 'required',
            'transactions_bulk_matches.*.options.desc' => 'nullable',
            'transactions_bulk_matches.*.amount' => [
                'required_unless:transactions_bulk_matches.*.options.amount_type,ANY',
                //'numeric', // Optionally enforce numeric validation here if applicable
            ],
            'transactions_bulk_matches.*.distribution_id' => 'required_unless:transactions_bulk_matches.*.split,true',
            'transactions_bulk_matches.*.split' => 'nullable',
            'transactions_bulk_matches.*.splits.*.amount' => 'nullable',
            'transactions_bulk_matches.*.splits.*.amount_type' => 'nullable',
            'transactions_bulk_matches.*.splits.*.distribution_id' => 'nullable',
        ];
    }

    public function updated($field, $value)
    {
        // dd($field, $value);
        $this->validateOnly($field);
    }

    public function updatedTransactionsBulkMatches($value, $field)
    {
        if (str_contains($field, 'options.amount_type')) {
            // Extract the index of the item being updated
            $index = explode('.', $field)[0];
            $this->transactions_bulk_matches[$index]->amount = NULL;
        }

        // dd($field);
        // if (str_contains($field, 'split')) {
        //     // Extract the index of the item being updated
        //     $index = explode('.', $field)[0];
        //     dd($index);
        //     $this->transactions_bulk_matches[$index]->distribution_id = NULL;
        // }
        //splits.*.amount_type
        elseif (str_contains($field, 'amount_type')) {
            $index = explode('.', $field)[0];
            $amount_type = explode('.', $field)[3];

            //double check only / not really needed
            // if($amount_type === 'amount_type'){
            //     foreach($this->transactions_bulk_matches[$index]->splits as $split_index => $split){
            //         $split['amount_type'] = $value;
            //         // $this->transactions_bulk_matches[$index]['splits'][$split_index]['amount_type'] = $value;
            //         // $this->transactions_bulk_matches[$index]->splits[$split_index]['amount_type'] = $value;
            //         // $split['amount'] = NULL;
            //     }
            // }
        }
    }

    // public function getMatchesDisabledProperty()
    // {
    //     return collect($this->transactions_bulk_matches)->map(function ($match) {
    //         return ($match['options']['amount_type'] ?? '') === 'ANY';
    //     })->toArray();
    // }

    public function api_login()
    {
        $login_route = $this->vendor->receipts->first()->options['api_route'];
        $this->redirectRoute($login_route);
    }

    public function editReceiptVendor(Vendor $vendor)
    {
        $this->vendor = $vendor->load(['receipts', 'receipt_account', 'transactions_bulk_match']);
        $this->transactions_bulk_matches = $this->vendor->transactions_bulk_match;
        foreach($this->transactions_bulk_matches as $index => $match){
            if($match->options['splits'] ?? false){
                $match->splits = collect($match->options['splits']);
                $match->split = true;
            }
        }

        // dd($this->vendor?->receipt_account()?->exists() ?? false);
        $this->distribution_id = NULL;
        if (isset($this->vendor->receipt_account)) {
            $receipt_account = $this->vendor->receipt_account;
            if ($receipt_account->distribution_id) {
                $this->distribution_id = $receipt_account->distribution_id;
            } elseif ($receipt_account->project_id === 0) {
                $this->distribution_id = 'NO_PROJECT';
            }
        }

        // $this->vendor->logged_in = $this->vendor->receipt_account && $this->vendor->receipt_account->options ? ($this->vendor->receipt_account->options['access_token'] ? true : false) : false;
        // $this->vendor->logged_in = isset($this->vendor->receipt_account->options) ? (isset($this->vendor->receipt_account->options['errors']) ? false : true) : false;

        $this->modal('receipt_account_vendor_form_modal')->show();
    }

    public function addMatch()
    {
        if($this->transactions_bulk_matches->isEmpty()){
            $this->transactions_bulk_matches = collect();
        }

        $this->transactions_bulk_matches->push(new TransactionBulkMatch(['options' => ['amount_type' => 'ANY', 'desc' => NULL]]));
    }

    public function addSplit($transactions_bulk_matches_index)
    {
        $match = $this->transactions_bulk_matches[$transactions_bulk_matches_index];

        if(is_null($match->splits)){
            $match->split = TRUE;
            $match->distribution_id = NULL;
            $match->splits = collect();
            $match->splits->push(['amount_type' => '$', 'amount' => NULL]);
        }

        $match->splits->push(['amount_type' => '$', 'amount' => NULL]);
    }

    public function removeSplit($transactions_bulk_matches_index, $split_index)
    {
        $this->transactions_bulk_matches[$transactions_bulk_matches_index]->splits->forget($split_index);
    }

    public function removeMatch($index)
    {
        $this->transactions_bulk_matches->forget($index);
    }

    public function store()
    {
        $this->validate();

        if (is_numeric($this->distribution_id)) {
            $distribution_id = $this->distribution_id;
            $project_id = null;
        } else {
            //NO PROJECT
            $distribution_id = null;
            $project_id = 0;
        }

        if (is_null($this->vendor->receipt_account)) {
            //create new
            $receipt_account = new ReceiptAccount;
        } else {
            //edit existing
            $receipt_account = $this->vendor->receipt_account;
        }

        $receipt_account->project_id = $project_id;
        $receipt_account->distribution_id = $distribution_id;
        $receipt_account->belongs_to_vendor_id = auth()->user()->vendor->id;
        $receipt_account->vendor_id = $this->vendor->id;
        $receipt_account->save();

        $matches_not_removed = $this->transactions_bulk_matches->pluck('id')->toArray();
        $matches_to_remove = $this->vendor->transactions_bulk_match()->whereNotIn('id', $matches_not_removed)->get();

        foreach($matches_to_remove as $remove_match){
            $remove_match->delete();
        }

        //create TransactionBulkMatch
        foreach($this->transactions_bulk_matches as $bulk_match){
            $bulk_match->vendor_id = $this->vendor->id;
            $bulk_match->options = array_merge($bulk_match->options, ['splits' => $bulk_match->splits ? $bulk_match->splits->toArray() : []]);
            unset($bulk_match->splits);
            unset($bulk_match->split);
            $bulk_match->belongs_to_vendor_id = auth()->user()->vendor->id;
            $bulk_match->save();
        }

        $this->modal('receipt_account_vendor_form_modal')->close();
        $this->dispatch('refreshComponent')->to('receipt-accounts.receipt-accounts-index');

        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'Match Created',
            // route / href / wire:click
            text: '',
        );

        //queue TransactionController@transaction_vendor_bulk_match
        TransactionVendorBulkMatchJob::dispatch();
    }

    public function render()
    {
        $this->authorize('create', TransactionBulkMatch::class);

        return view('livewire.receipt-accounts.vendor-create');
    }
}

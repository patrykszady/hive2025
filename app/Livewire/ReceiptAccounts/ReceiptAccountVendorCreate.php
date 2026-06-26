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
use Illuminate\Support\Facades\Crypt;
use Livewire\Attributes\Computed;
use Livewire\Component;

class ReceiptAccountVendorCreate extends Component
{
    use AuthorizesRequests;
    protected $listeners = ['refreshComponent' => '$refresh', 'editReceiptVendor'];

    // public BulkMatchForm $form;
    public $distributions = []; //coming from ReceiptAccountsIndex

    public $transactions_bulk_matches = []; //simple array, not models
    public $vendor_transactions = [];

    public array $credential_fields = [];
    public array $credential_values = [];

    public Vendor $vendor;

    //$this->form->setMatch($match);

    protected function rules()
    {
        return [
            'vendor.logged_in' => 'nullable',
            'transactions_bulk_matches.*.options.amount_type' => 'required',
            'transactions_bulk_matches.*.options.desc' => 'nullable',
            'transactions_bulk_matches.*.amount' => [
                'required_unless:transactions_bulk_matches.*.options.amount_type,ANY',
            ],
            'transactions_bulk_matches.*.distribution_id' => 'nullable',
            'transactions_bulk_matches.*.split' => 'nullable',
            'transactions_bulk_matches.*.splits.*.amount' => 'nullable',
            'transactions_bulk_matches.*.splits.*.amount_type' => 'nullable',
            'transactions_bulk_matches.*.splits.*.distribution_id' => 'nullable',
        ];
    }

    public function updated($field, $value)
    {
        $this->validateOnly($field);
    }

    public function updatedTransactionsBulkMatches($value, $field)
    {
        if (str_contains($field, 'options.amount_type')) {
            // Extract the index of the item being updated
            $index = explode('.', $field)[0];
            if(isset($this->transactions_bulk_matches[$index])) {
                $this->transactions_bulk_matches[$index]['amount'] = null;
            }
        }

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
        // Explicitly clear all state before loading new vendor
        $this->transactions_bulk_matches = [];
        $this->vendor_transactions = [];
        $this->credential_fields = [];
        $this->credential_values = [];

        $this->vendor = $vendor->load(['transactions', 'receipts', 'receipt_account', 'transactions_bulk_match']);

        // Load credential field definitions from the vendor's receipt config
        $this->credential_fields = $this->vendor->receipts->first()?->options['credential_fields'] ?? [];

        // Load existing credential values from options (passwords are never sent back)
        $options = $this->vendor->receipt_account->options ?? [];
        $this->credential_values = [];
        foreach ($this->credential_fields as $field) {
            $key = $field['key'];
            $raw = $options[$key] ?? '';
            if ((($field['encrypted'] ?? false) || ($field['type'] ?? '') === 'password') && $raw) {
                try {
                    $this->credential_values[$key] = Crypt::decryptString($raw);
                } catch (\Exception $e) {
                    $this->credential_values[$key] = '';
                }
            } else {
                $this->credential_values[$key] = $raw;
            }
        }

        // Convert models to plain arrays like BulkMatchCreate does
        $this->transactions_bulk_matches = $this->vendor->transactions_bulk_match->map(function($match) {
            return [
                'id' => $match->id,
                'amount' => $match->amount,
                'distribution_id' => $match->distribution_id,
                'options' => $match->options ?? ['amount_type' => 'ANY', 'desc' => null],
                'split' => isset($match->options['splits']) && !empty($match->options['splits']),
                'splits' => $match->options['splits'] ?? null,
            ];
        })->toArray();

        $this->vendor_transactions = $this->vendor->transactions()
            ->with(['expense.distribution']) // Eager load only distribution relationships
            ->get()
            ->groupBy('amount') // Group by amount
            ->filter(function ($transactionsGroup) {
                // Keep groups with at least one transaction without an expense OR at least 2 transactions
                return $transactionsGroup->contains(function ($transaction) {
                    return is_null($transaction->expense); // Check for missing expenses
                }) || $transactionsGroup->count() >= 2; // Or group has at least 2 transactions
            })
            ->map(function ($transactionsGroup) {
                $distributionsCount = $transactionsGroup
                    ->groupBy(function ($transaction) {
                        if ($transaction->expense && !is_null($transaction->expense->distribution_id)) {
                            // Group by distribution name if available
                            return $transaction->expense->distribution->name ?? 'Unknown Distribution';
                        }
                        return 'No Distribution'; // Default grouping for transactions without an expense or distribution
                    })
                    ->map->count(); // Count transactions in each distribution group

                return [
                    'count' => $transactionsGroup->count(),
                    'distributions_count' => $distributionsCount, // Updated key name
                ];
            })
            ->sortByDesc('count'); // Sort by total count for each amount group

        // dd($this->vendor?->receipt_account()?->exists() ?? false);

        $this->modal('receipt_account_vendor_form_modal')->show();
    }

    public function addMatch()
    {
        $this->transactions_bulk_matches[] = [
            'id' => null,
            'amount' => null,
            'distribution_id' => null,
            'options' => ['amount_type' => 'ANY', 'desc' => null],
            'split' => false,
            'splits' => null,
        ];
    }

    public function toggleSplit($transactions_bulk_matches_index)
    {
        if(!isset($this->transactions_bulk_matches[$transactions_bulk_matches_index])) {
            return;
        }

        $current = $this->transactions_bulk_matches[$transactions_bulk_matches_index]['split'] ?? false;

        if($current) {
            // Disable split mode
            $this->transactions_bulk_matches[$transactions_bulk_matches_index]['split'] = false;
            $this->transactions_bulk_matches[$transactions_bulk_matches_index]['splits'] = null;
            $this->transactions_bulk_matches[$transactions_bulk_matches_index]['distribution_id'] = null;
        } else {
            // Enable split mode with 2 default splits
            $this->transactions_bulk_matches[$transactions_bulk_matches_index]['split'] = true;
            $this->transactions_bulk_matches[$transactions_bulk_matches_index]['distribution_id'] = null;
            $this->transactions_bulk_matches[$transactions_bulk_matches_index]['splits'] = [
                ['amount_type' => '$', 'amount' => null, 'distribution_id' => null],
                ['amount_type' => '$', 'amount' => null, 'distribution_id' => null],
            ];
        }
    }

    public function addSplit($transactions_bulk_matches_index)
    {
        if(!isset($this->transactions_bulk_matches[$transactions_bulk_matches_index])) {
            return;
        }

        if(is_null($this->transactions_bulk_matches[$transactions_bulk_matches_index]['splits'])){
            $this->transactions_bulk_matches[$transactions_bulk_matches_index]['split'] = true;
            $this->transactions_bulk_matches[$transactions_bulk_matches_index]['distribution_id'] = null;
            $this->transactions_bulk_matches[$transactions_bulk_matches_index]['splits'] = [
                ['amount_type' => '$', 'amount' => null, 'distribution_id' => null],
            ];
        }

        $this->transactions_bulk_matches[$transactions_bulk_matches_index]['splits'][] = [
            'amount_type' => '$',
            'amount' => null,
            'distribution_id' => null,
        ];
    }

    public function removeSplit($transactions_bulk_matches_index, $split_index)
    {
        if(!isset($this->transactions_bulk_matches[$transactions_bulk_matches_index]['splits'][$split_index])) {
            return;
        }

        unset($this->transactions_bulk_matches[$transactions_bulk_matches_index]['splits'][$split_index]);
        $this->transactions_bulk_matches[$transactions_bulk_matches_index]['splits'] = array_values(
            $this->transactions_bulk_matches[$transactions_bulk_matches_index]['splits']
        );

        // If no splits left, clear split mode
        if(empty($this->transactions_bulk_matches[$transactions_bulk_matches_index]['splits'])) {
            $this->transactions_bulk_matches[$transactions_bulk_matches_index]['split'] = false;
            $this->transactions_bulk_matches[$transactions_bulk_matches_index]['splits'] = null;
        }
    }

    public function removeMatch($index)
    {
        unset($this->transactions_bulk_matches[$index]);
        $this->transactions_bulk_matches = array_values($this->transactions_bulk_matches);
    }

    public function store()
    {
        $this->validate();

        if (is_null($this->vendor->receipt_account)) {
            $receipt_account = new ReceiptAccount;
        } else {
            $receipt_account = $this->vendor->receipt_account;
        }

        $receipt_account->belongs_to_vendor_id = auth()->user()->vendor->id;
        $receipt_account->vendor_id = $this->vendor->id;

        // Persist credential values into options
        $existingOptions = $receipt_account->options ?? [];
        foreach ($this->credential_fields as $field) {
            $key = $field['key'];
            $value = $this->credential_values[$key] ?? '';
            if ($value === '') {
                continue; // Skip empty — preserves existing saved value
            }
            if (($field['encrypted'] ?? false) || ($field['type'] ?? '') === 'password') {
                $existingOptions[$key] = Crypt::encryptString($value);
            } else {
                $existingOptions[$key] = $value;
            }
        }
        $receipt_account->options = $existingOptions;

        $receipt_account->save();

        $matches_not_removed = collect($this->transactions_bulk_matches)->pluck('id')->filter()->toArray();
        $matches_to_remove = $this->vendor->transactions_bulk_match()->whereNotIn('id', $matches_not_removed)->get();

        foreach($matches_to_remove as $remove_match){
            $remove_match->delete();
        }

        //create or update TransactionBulkMatch
        foreach($this->transactions_bulk_matches as $bulk_match_data){
            $bulk_match = $bulk_match_data['id'] 
                ? TransactionBulkMatch::find($bulk_match_data['id'])
                : new TransactionBulkMatch();

            $bulk_match->vendor_id = $this->vendor->id;
            $bulk_match->amount = $bulk_match_data['amount'];
            $bulk_match->distribution_id = $bulk_match_data['distribution_id'] ?: null;
            $bulk_match->options = array_merge(
                $bulk_match_data['options'] ?? [],
                ['splits' => $bulk_match_data['splits'] ?? []]
            );
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
        return view('livewire.receipt-accounts.vendor-create', [
            'vendor_transactions' => $this->vendor_transactions ?? [],
        ]);
    }
}

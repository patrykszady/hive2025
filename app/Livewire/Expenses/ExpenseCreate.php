<?php

namespace App\Livewire\Expenses;

use App\Livewire\Forms\ExpenseForm;
use App\Models\BankAccount;
use App\Models\Check;
use App\Models\Distribution;
use App\Models\Expense;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\Transaction;
use App\Models\Vendor;
use App\Traits\HandlesChecks;
use Flux;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

class ExpenseCreate extends Component
{
    use WithFileUploads, HandlesChecks;

    public ExpenseForm $form;
    public Expense $expense;

    public $split = false;
    public $splits = false;
    public $expense_splits = [];
    
    public $existing_check_id = null;

    public $view_text = [
        'card_title' => 'Create Expense',
        'button_text' => 'Create',
        'form_submit' => 'save',
    ];

    protected $listeners = ['resetModal', 'editExpense', 'newExpense', 'createExpenseFromTransaction', 'hasSplits'];

    public function mount()
    {
        $this->expense = Expense::make();
        // No data loading here - we'll use computed properties instead
    }

    #[Computed]
    public function employees()
    {
        return auth()->user()->vendor->users()->employed()->get();
    }

    #[Computed]
    public function via_vendor_employees()
    {
        return auth()->user()->vendor->users()->employed()->wherePivotNotNull('via_vendor_id')->get();
    }

    #[Computed]
    public function vendors()
    {
        return Vendor::orderBy('business_name')->get(['id', 'business_name']);
    }

    #[Computed]
    public function projects()
    {
        return Project::with('latestStatus')
            ->notCancelled()
            ->orderByLatestStatusDateDesc()
            ->get(['id', 'project_name', 'address']);
    }

    #[Computed]
    public function distributions()
    {
        return Distribution::all(['id', 'name']);
    }
    
    #[Computed]
    public function available_checks()
    {
        // Get recent checks from the last 60 days for current vendor
        return Check::with('vendor', 'bank_account.bank')
            ->whereNull('deleted_at')
            ->where('date', '>=', now()->subDays(60))
            ->orderBy('date', 'DESC')
            ->limit(50)
            ->get()
            ->map(function ($check) {
                $label = "#{$check->id} - " . $check->check_type;
                if ($check->check_type === 'Check') {
                    $label .= " #{$check->check_number}";
                }
                $label .= " - $" . number_format($check->amount, 2);
                $label .= " - " . $check->date->format('m/d/Y');
                if ($check->vendor) {
                    $label .= " - " . $check->vendor->business_name;
                }
                return [
                    'id' => $check->id,
                    'label' => $label,
                ];
            });
    }

    // Your existing methods don't need to change since they'll now 
    // automatically access the computed properties when needed

    public function updated($field, $value)
    {
        // if SPLIT checked vs if unchecked
        if ($field == 'split') {
            if ($this->split == true) {
                $this->form->split = true;
                $this->form->project_id = null;
            } else {
                $this->form->split = false;
            }
        }

        if ($field === 'form.paid_by') {
            if ($value === 'NULL') {
                $this->form->paid_by = null;
            }
        }

        if ($field == 'form.reimbursment') {
            $this->validateOnly('form.receipt_file');
        }

        if ($field == 'form.project_id' && is_numeric($value)) {
            $project_status_code = $this->projects->where('id', $value)->first()->latestStatus->status_code;

            if ($project_status_code == 7) { // Complete
                $this->form->project_completed = true;
            } else {
                $this->form->project_completed = false;
            }
        } else {
            $this->form->project_completed = false;
        }

        // Add this to handle check_type updates correctly
        if ($field === 'check_type') {
            if ($value === 'Check') {
                $this->autoCheckNumber(); // This will set next_check_auto to true
            } else {
                $this->check_number = null;
                $this->next_check_auto = false;
            }
        }

        $this->validateOnly($field);
    }

    //$saved_splits
    public function hasSplits($saved_splits)
    {
        $this->expense_splits = $saved_splits;
        $this->splits = true;
        $this->split = true;
        $this->form->split = true;
    }

    public function newExpense($amount)
    {
        $this->clearCheckFields();
        $this->expense = Expense::make();
       
        $this->form->amount = $amount;
        $this->view_text = [
            'card_title' => 'Create Expense',
            'button_text' => 'Create',
            'form_submit' => 'save',
        ];

        $this->modal('expenses_form_modal')->show();
    }

    public function editExpense(Expense $expense)
    {
        $this->resetModal();

        $this->expense = $expense;
        $this->form->setExpense($expense);

        if (! $expense->splits->isEmpty()) {
            $this->hasSplits($expense->splits);
        }    

        $this->view_text = [
            'card_title' => 'Update Expense',
            'button_text' => 'Update',
            'form_submit' => 'edit',
        ];

        $this->modal('expenses_form_modal')->show();
    }

    public function resetModal()
    {
        $this->expense = Expense::make();
        $this->form->reset();
        $this->clearCheckFields();
        $this->existing_check_id = null;
        $this->dispatch('resetSplits')->to('expenses.expense-splits-create');
        $this->split = false;
        $this->splits = false;
        $this->expense_splits = [];
        // Public functions should be reset here
        // $this->dispatch('resetSplits')->to('expenses.expenses-splits-form');
        // $this->dispatch('refreshComponent')->to('expenses.expenses-splits-form');
        // $this->dispatch('resetSplits');
        $this->modal('expenses_form_modal')->close();

        // $this->transaction = NULL;
        // $this->check = Check::make();
        // $this->resetValidation();
    }

    #[Computed]
    public function shouldShowMerchantName()
    {
        if (!isset($this->form->merchant_name) || empty($this->form->merchant_name)) {
            return false;
        }
        
        if (!$this->form->vendor_id) {
            return true;
        }
        
        $selectedVendor = $this->vendors->firstWhere('id', $this->form->vendor_id);
        return !$selectedVendor || $this->form->merchant_name != $selectedVendor->business_name;
    }

    #[Computed]
    public function notesSummary()
    {
        // Only show notes summary when editing an existing expense
        if (!$this->expense->exists) {
            return null;
        }

        $allNotes = [];

        // Collect individual note parts from all receipts (uses orderedReceipts relationship)
        foreach ($this->expense->orderedReceipts as $receipt) {
            if (!empty($receipt->notes)) {
                // Split by ' | ' to get individual parts, then merge
                $parts = array_map('trim', explode(' | ', $receipt->notes));
                foreach ($parts as $part) {
                    if ($part !== '') {
                        $allNotes[] = $part;
                    }
                }
            }
        }

        // Fuzzy deduplicate notes to handle OCR variations
        $unique = $this->fuzzyDeduplicateNotes($allNotes);

        return !empty($unique) ? implode(', ', $unique) : null;
    }

    /**
     * Deduplicate notes using fuzzy matching to handle OCR errors.
     * Strings with >85% similarity are considered duplicates.
     */
    private function fuzzyDeduplicateNotes(array $notes): array
    {
        $unique = [];
        
        foreach ($notes as $note) {
            $isDuplicate = false;
            $noteLower = strtolower($note);
            
            foreach ($unique as $existing) {
                $existingLower = strtolower($existing);
                
                // Exact match
                if ($noteLower === $existingLower) {
                    $isDuplicate = true;
                    break;
                }
                
                // Fuzzy match using similar_text percentage
                similar_text($noteLower, $existingLower, $percent);
                if ($percent > 85) {
                    $isDuplicate = true;
                    break;
                }
            }
            
            if (!$isDuplicate) {
                $unique[] = $note;
            }
        }
        
        return $unique;
    }

    public function createExpenseFromTransaction(Transaction $transaction)
    {
        $this->resetModal();
        $this->dispatch('resetSplits')->to('expenses.expense-splits-create');
        // {
        //6-14-2022 this only works for Retail vendors.. really need a Modal from MatchVendor or VendorCreate forms and taken back here
        //create Retail vendor here if doesnt exist yet
        // if(is_null($transaction->vendor_id)){
        //     $vendor = Vendor::create([
        //         'business_type' => 'Retail',
        //         'business_name' => $transaction->plaid_merchant_name,
        //     ]);

        //     $vendor_id = $vendor->id;

        //     //USED IN MULTIPLE OF PLACES TransactionController@add_vendor_to_transactions, MatchVendor@store
        //     //add if vendor is not part of the currently logged in vendor
        //     if(!$transaction->bank_account->vendor->vendors->contains($vendor_id)){
        //         $transaction->bank_account->vendor->vendors()->attach($vendor_id);
        //     }

        //     //add this vendor to the existing $this->vendors collection
        //     $this->vendors->add($vendor);

        //     //6-8-2022 run in a queue?
        //     app('App\Http\Controllers\TransactionController')->add_vendor_to_transactions();
        // }else{
        //     $vendor_id = $transaction->vendor_id;
        // }
        // }

        // $this->expense_splits = [];

        //2/18/2023 if check_number .. expense->vendor_id = GS Construction / logged in vendor?
        if ($transaction->check_number) {
            if ($transaction->check_number === '1010101') {
                $check_type = 'Transfer';
            } elseif ($transaction->check_number === '2020202') {
                $check_type = 'Cash';
            } else {
                $check_type = 'Check';
            }

            $this->bank_account_id = $transaction->bank_account_id;
            $this->check_type = $check_type;

            //2/18/2023 dont allow changes to $this->check if coming from a transaction...
            if ($check_type === 'Check') {
                $this->check_number = $transaction->check_number;
            }
        }

        $this->form->transaction = $transaction;

        $this->view_text = [
            'card_title' => 'Create Expense from Transaction',
            'button_text' => 'Create',
            'form_submit' => 'save',
        ];

        $this->form->amount = $transaction->amount;
        $this->form->date = $transaction->transaction_date->format('Y-m-d');

        if (is_null($transaction->vendor_id)) {
            $this->form->vendor_id = null;
        } else {
            $this->form->vendor_id = $transaction->vendor_id;
        }

        $this->modal('expenses_form_modal')->show();
    }

    public function edit()
    {
        //return with Error... splits needed if Project is SPLIT
        if ($this->split == true && empty($this->expense_splits)) {
            return $this->addError('no_splits', 'Splits required if Project is Split');
        }

        $expense = $this->form->update();
        $this->modal('expenses_form_modal')->close();

        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'Expense Updated.',
            // route / href / wire:click
            text: money($expense->amount),
        );

        $this->dispatch('resetSplits')->to('expenses.expense-splits-create');
        $this->dispatch('refreshComponent')->to('expenses.expense-index');
        $this->dispatch('refreshComponent')->to('expenses.expense-show');
    }

    public function remove()
    {
        $this->form->delete();
        $this->modal('expenses_form_modal')->close();

        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'Expense Deleted.',
            // route / href / wire:click
            text: '',
        );

        $this->dispatch('refreshComponent')->to('expenses.expense-index');
    }

    public function save()
    {
        //return with Error... splits needed if Project is SPLIT
        if ($this->split == true && empty($this->expense_splits)) {
            return $this->addError('no_splits', 'Splits required if Project is Split');
        }

        $expense = $this->form->store($this->existing_check_id);
        
        // If user selected an existing check, recalculate check amount
        if ($this->existing_check_id) {
            $check = Check::find($this->existing_check_id);
            $check->amount = $check->expenses->sum('amount') + $check->timesheets->sum('amount');
            $check->save();
        }
        
        $this->modal('expenses_form_modal')->close();

        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'Expense Created.',
            // route / href / wire:click
            text: '',
        );

        $this->resetModal();
        
        // If created from transaction, dispatch event to remove it from the UI
        if ($this->form->transaction) {
            $this->dispatch('transaction-used', transactionId: $this->form->transaction->id);
        }
        //queue

        $this->dispatch('refreshComponent')->to('expenses.expense-index');
    }

    public function render()
    {
        // $this->authorize('create', Expense::class);
        return view('livewire.expenses.form');
    }
}

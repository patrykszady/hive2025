<?php

namespace App\Livewire\Expenses;

use App\Livewire\Forms\ExpenseForm;
use App\Models\BankAccount;
use App\Models\Check;
use App\Models\Distribution;
use App\Models\Expense;
use App\Models\Project;
use App\Models\Transaction;
use App\Models\Vendor;
use App\Traits\HandlesChecks;
use Flux;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;
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
        return Project::orderBy('created_at', 'desc')->get();
    }

    #[Computed]
    public function distributions()
    {
        return Distribution::all(['id', 'name']);
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
            // if($value == NULL){
            //     $this->form->reimbursment = NULL;
            // }elseif($value == 'client_reimbursement'){
            //     // dd('Client');
            //     $this->form->reimbursment = 'client_reimbursement';
            // }

            // if($title == 'Complete' && $this->form->reimbursment == 'Client'){
            //     $this->addError('form.reimbursment', 'No Client reimbursment allowed when Project is Complete.');
            $this->validateOnly('form.receipt_file');
        }

        if ($field == 'form.project_id' && is_numeric($value)) {
            $project_title = $this->projects->where('id', $value)->first()->latestStatus->title;

            if ($project_title == 'Complete') {
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
        $this->expense = Expense::make();
    $this->clearCheckFields();
       
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
        $this->clearCheckFields();
        $this->form->setExpense($expense);

        // If the expense already has a check, prefill the component-level check fields
        if ($expense->check) {
            $this->bank_account_id = $expense->check->bank_account_id;
            $this->check_type = $expense->check->check_type;
            $this->check_number = $expense->check->check_number;
            $this->next_check_auto = false;
            $this->auto_check_number = null;
        }

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

    public function createExpenseFromTransaction(Transaction $transaction)
    {
        try {
            $this->resetModal();
            $this->dispatch('resetSplits')->to('expenses.expense-splits-create');
            $this->clearCheckFields();

            // If the transaction has a check_number, derive check_type and prefill fields
            if ($transaction->check_number) {
                if ($transaction->check_number === '1010101') {
                    $check_type = 'Transfer';
                } elseif ($transaction->check_number === '2020202') {
                    $check_type = 'Cash';
                } else {
                    $check_type = 'Check';
                }

                // Populate component-level check fields used by the partial
                $this->bank_account_id = $transaction->bank_account_id;
                $this->check_type = $check_type;
                $this->next_check_auto = false;
                $this->auto_check_number = null;
                $this->check_number = null;

                if ($check_type === 'Check' && !in_array($transaction->check_number, ['1010101', '2020202'])) {
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
        } catch (\Throwable $e) {
            throw $e; // rethrow to preserve existing behavior
        }
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

        $expense = $this->form->store();
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
        //queue

        $this->dispatch('refreshComponent')->to('expenses.expense-index');
    }

    public function render()
    {
        // $this->authorize('create', Expense::class);
        return view('livewire.expenses.form');
    }
}

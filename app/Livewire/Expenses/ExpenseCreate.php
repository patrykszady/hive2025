<?php

namespace App\Livewire\Expenses;

// use App\Jobs\UpdateProjectDistributionsAmount;
use App\Livewire\Forms\ExpenseForm;
use App\Models\BankAccount;
use App\Models\Check;
use App\Models\Distribution;
use App\Models\Expense;
use App\Models\Project;
use App\Models\Transaction;
use App\Models\Vendor;
use Flux;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

class ExpenseCreate extends Component
{
    use WithFileUploads;

    public ExpenseForm $form;

    public $view_text = [
        'card_title' => 'Create Expense',
        'button_text' => 'Create',
        'form_submit' => 'save',
    ];

    public $split = false;

    public $splits = false;

    public Expense $expense;

    public $expense_update = false;

    public $expense_splits = [];

    protected $listeners = ['resetModal', 'editExpense', 'newExpense', 'createExpenseFromTransaction', 'hasSplits'];

    public function mount()
    {
        $this->expense = Expense::make();
    }

    #[Computed]
    public function vendors()
    {
        $vendors = Vendor::orderBy('business_name')->get(['id', 'business_name']);
        return $vendors;
    }

    #[Computed]
    public function projects()
    {
        $projects = Project::status(['Active', 'Complete', 'Service Call', 'Service Call Complete'])->sortByDesc('last_status.start_date');
        return $projects;
    }

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
            // dd($value);
            // if($value == NULL){
            //     $this->form->reimbursment = NULL;
            // }elseif($value == 'client_reimbursement'){
            //     // dd('Client');
            //     $this->form->reimbursment = 'client_reimbursement';
            // }
            // $title = Project::findOrFail($this->form->project_id)->project_status->title;

            // if($title == 'Complete' && $this->form->reimbursment == 'Client'){
            //     $this->addError('form.reimbursment', 'No Client reimbursment allowed when Project is Complete.');
            // }
            // $this->validate();
            $this->validateOnly('form.receipt_file');
        }

        if ($field == 'form.project_id' && is_numeric($value)) {
            $project_title = $this->projects->where('id', $value)->first()->last_status->title;

            if ($project_title == 'Complete') {
                $this->form->project_completed = true;
            } else {
                $this->form->project_completed = false;
            }
        } else {
            $this->form->project_completed = false;
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
        $this->dispatch('resetSplits')->to('expenses.expense-splits-create');
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
        $this->dispatch('resetSplits')->to('expenses.expense-splits-create');

        $this->expense = $expense;
        $this->expense_update = true;

        if (! $expense->splits->isEmpty()) {
            $this->hasSplits($expense->splits);
        }

        $this->form->setExpense($expense);

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
        $this->dispatch('resetSplits')->to('expenses.expense-splits-create');
        $this->split = false;
        $this->splits = false;
        $this->expense_splits = [];
        $this->expense_update = false;
        // Public functions should be reset here
        // $this->dispatch('resetSplits')->to('expenses.expenses-splits-form');
        // $this->dispatch('refreshComponent')->to('expenses.expenses-splits-form');
        // $this->dispatch('resetSplits');
        $this->modal('expenses_form_modal')->close();

        // $this->transaction = NULL;
        // $this->check = Check::make();
        // $this->resetValidation();
    }

    public function createExpenseFromTransaction(Transaction $transaction)
    {
        $this->resetModal();
        $this->dispatch('resetSplits')->to('expenses.expense-splits-create');
        // {
        //6-14-2022 this only works for Retail vendors.. really need a Modal from MatchVendor or CreateNewVendor forms and taken back here
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
            if ($transaction->check_number == '1010101') {
                $check_type = 'Transfer';
            } elseif ($transaction->check_number == '2020202') {
                $check_type = 'Cash';
            } else {
                $check_type = 'Check';
            }

            $this->form->bank_account_id = $transaction->bank_account_id;
            $this->form->check_type = $check_type;

            //2/18/2023 dont allow changes to $this->check if coming from a transaction...
            if ($check_type == 'Check') {
                $this->form->check_number = $transaction->check_number;
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
        // $this->resetModal();

        //queue
        // UpdateProjectDistributionsAmount::dispatch($expense->project, $expense->project->distributions->pluck('id')->toArray());

        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'Expense Updated.',
            // route / href / wire:click
            text: money($expense->amount),
        );

        $this->dispatch('resetSplits')->to('expenses.expense-splits-create');
        $this->dispatch('refreshComponent')->to('expenses.expense-show');
        $this->dispatch('refreshComponent')->to('expenses.expense-index');
        $this->dispatch('refreshComponent')->to('projects.project-show');
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

        $this->resetModal();

        // if($this->form->transaction){
        //     $remove_type = 'transaction';
        // }else{
        //     $remove_type = 'expense';
        // }

        // if($remove_type == 'transaction'){
        //     $transaction = $this->form->transaction;
        //     $transaction->delete();

        //     $this->dispatch('refreshComponent')->to('expenses.expense-index');


        //     $expense = $this->form->delete();

        //     $url = url()->previous();
        //     $route = app('router')->getRoutes($url)->match(app('request')->create($url))->getName();

        //     if($route == 'expenses.show'){
        //         session()->flash('notify', ['success', 'Expense Deleted']);
        //         $this->redirect(ExpenseIndex::class);
        //     }else{
        //         $this->dispatch('refreshComponent')->to('expenses.expense-index');


        //         );
        //     }

        //     //queue
        //     // UpdateProjectDistributionsAmount::dispatch($this->form->expense->project, $this->form->expense->project->distributions->pluck('id')->toArray());
        //     // $this->dispatch('refreshComponent')->to('expenses.expense-show');
        //     $this->dispatch('refreshComponent')->to('expenses.expense-index');
        // }
    }

    public function save()
    {
        //return with Error... splits needed if Project is SPLIT
        if ($this->split == true && empty($this->expense_splits)) {
            return $this->addError('no_splits', 'Splits required if Project is Split');
        }
        // return $this->dispatch('validateCheck')->to(CheckCreate::class);
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
        // UpdateProjectDistributionsAmount::dispatch($expense->project, $expense->project->distributions->pluck('id')->toArray());

        //dispatch and refresh so expenses-new-form removes/refreshes
        //coming from different components expenses-show, expenses-index....

        // $this->dispatch('resetSplits')->to('expenses.expense-splits-create');
        // $this->dispatch('refreshComponent')->to('expenses.expense-show');
        // $this->dispatch('refreshComponent')->to('expenses.expense-index');
        // $this->dispatch('refreshComponent')->to('projects.project-show');
    }

    public function render()
    {
        $this->authorize('create', Expense::class);

        $distributions = Distribution::all(['id', 'name']);
        $team_members = auth()->user()->vendor->users()->employed();
        $employees = $team_members->get();
        $via_vendor_employees = $team_members->wherePivotNotNull('via_vendor_id')->get();

        $bank_accounts =
            BankAccount::with('bank')->where('type', 'Checking')
                ->whereHas('bank', function ($query) {
                    return $query->whereNotNull('plaid_access_token');
                })->get();

        return view('livewire.expenses.form', [
            'distributions' => $distributions,
            'via_vendor_employees' => $via_vendor_employees,
            'bank_accounts' => $bank_accounts,
            'employees' => $employees,
        ]);
    }
}

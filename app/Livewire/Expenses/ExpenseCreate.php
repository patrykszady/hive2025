<?php

namespace App\Livewire\Expenses;

use App\Livewire\Forms\ExpenseForm;
use App\Livewire\Expenses\ExpenseIndex;
use App\Models\BankAccount;
use App\Models\Check;
use App\Models\Distribution;
use App\Models\Expense;
use App\Models\ExpenseReceipts;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\Transaction;
use App\Models\Vendor;
use App\Traits\HandlesChecks;
use Flux;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
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

    public $upload_file = null;

    public bool $upload_is_material_order = false;

    public $upload_belongs_to_vendor_id = null;

    public bool $embedded = false;

    public $view_text = [
        'card_title' => 'Create Expense',
        'button_text' => 'Create',
        'form_submit' => 'save',
    ];

    protected $listeners = ['resetModal', 'editExpense', 'newExpense', 'createExpenseFromTransaction', 'hasSplits', 'openUploadReceipt'];

    protected function toastExpenseSuccess(Expense $expense, string $heading, ?string $text = null): void
    {
        $slots = [
            'heading' => $heading,
            'text' => $text,
            'action' => 'Go to expense',
        ];

        $this->dispatch('toast-show',
            duration: 5000,
            slots: array_filter($slots, fn ($value) => ! is_null($value)),
            dataset: [
                'variant' => 'success',
                'position' => 'top end',
                'route' => route('expenses.show', $expense),
            ],
        );
    }

    /**
     * The form's select lists (all vendors + projects + distributions) are
     * ~2.2MB of rendered options. Until one of the open events fires, the
     * modal body renders empty — the page that merely HOSTS this modal
     * doesn't pay for it. Flipped by every path that shows a modal; the
     * open event's own round trip brings the options.
     */
    public bool $hydrated = false;

    public function mount(?int $expenseId = null, bool $embedded = false): void
    {
        $this->embedded = $embedded;

        if ($embedded) {
            $this->hydrated = true;
        }

        $this->expense = Expense::make();

        if ($expenseId) {
            $loaded = Expense::find($expenseId);
            if ($loaded) {
                $this->editExpense($loaded);
            }
        }
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
    public function reimbursment_vendors()
    {
        // Sub / 1099 vendors that can owe the company back for expenses paid
        // on their behalf — stored as reimbursment = 'V:{vendor_id}' and
        // deducted from that vendor's next payment on /vendors/{id}/payment.
        return Vendor::whereIn('business_type', ['Sub', '1099'])
            ->orderBy('business_name')
            ->get(['id', 'business_name']);
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
            ->get(['id', 'project_name', 'address'])
            ->sortBy(fn ($project) => $project->latestStatus?->status_code === 6 ? 0 : 1)
            ->values();
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

    public function openSplits(): void
    {
        $this->dispatch(
            'addSplits',
            expense: $this->expense->id ?? null,
            amount: $this->form->amount,
        )->to(ExpenseSplitsCreate::class);
    }

    public function newExpense($amount)
    {
        $this->hydrated = true;
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

    public function openUploadReceipt(): void
    {
        $this->hydrated = true;
        $this->upload_file = null;
        $this->upload_is_material_order = false;
        $this->upload_belongs_to_vendor_id = null;
        $this->resetValidation('upload_file');
        $this->modal('upload_receipt_modal')->show();
    }

    public function editExpense(Expense $expense)
    {
        $this->hydrated = true;
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

        if (! $this->embedded) {
            $this->modal('expenses_form_modal')->show();
        }
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
        if (! $this->embedded) {
            $this->modal('expenses_form_modal')->close();
        }

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
        $this->hydrated = true;
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

        if (! $this->embedded) {
            $this->modal('expenses_form_modal')->show();
        }
    }

    public function edit()
    {
        //return with Error... splits needed if Project is SPLIT
        if ($this->split == true && empty($this->expense_splits)) {
            return $this->addError('no_splits', 'Splits required if Project is Split');
        }

        $expenseId = $this->expense->id;
        $expense = $this->form->update();
        if (! $this->embedded) {
            $this->modal('expenses_form_modal')->close();
        }

        // Optimistically remove row before server refresh (will be re-added with updated data)
        $this->js('window.dispatchEvent(new CustomEvent("remove-expense-row", { detail: { id: ' . $expenseId . ' } }))');

        $this->toastExpenseSuccess($expense, 'Expense Updated.', money($expense->amount));

        $this->dispatch('resetSplits')->to('expenses.expense-splits-create');
        $this->dispatch('refreshComponent')->to('expenses.expense-index');
        $this->dispatch('refreshComponent')->to('expenses.expense-show');

        if ($this->embedded) {
            $this->dispatch('embedded-expense-updated', expenseId: $expenseId);
        }
    }

    public function removeCheckAssociation(): void
    {
        if (! $this->expense->exists) {
            return;
        }

        $this->authorize('update', $this->expense);

        $checkIds = collect();
        if ($this->expense->check_id) {
            $checkIds->push($this->expense->check_id);
        }
        $checkIds = $checkIds->merge($this->expense->checks()->pluck('checks.id'));

        $this->expense->check_id = null;
        $this->expense->save();
        $this->expense->checks()->detach();
        $this->expense->searchable();

        $this->existing_check_id = null;
        $this->clearCheckFields();

        foreach ($checkIds->unique() as $checkId) {
            $check = Check::find($checkId);
            if (! $check) {
                continue;
            }

            // Reimbursement deductions must stay negative — use the shared recalc
            $check->recalculateAmount();
        }

        $this->expense->refresh();
        $this->dispatch('refreshComponent')->to('expenses.expense-show');
        $this->dispatch('refreshComponent')->to('expenses.expense-index');
        $this->dispatch('refreshComponent')->to('checks.check-show');

        Flux::toast('', 'Expense removed from check.', 5000, 'success', 'top right');
    }

    public function remove()
    {
        $isAdmin = auth()->user()?->vendor_role === 'Admin';

        if ($this->expense->transactions()->exists() && ! $isAdmin) {
            return;
        }

        $expenseId = $this->expense->id;
        $referer = (string) request()->header('Referer', '');
        $onShowPage = (bool) preg_match('~/expenses/\d+(?:[/?#]|$)~', $referer);
        $this->form->delete();
        if (! $this->embedded) {
            $this->modal('expenses_form_modal')->close();
        }

        // Optimistically remove row immediately
        $this->js('window.dispatchEvent(new CustomEvent("remove-expense-row", { detail: { id: ' . $expenseId . ' } }))');

        if ($onShowPage) {
            Flux::toast('', 'Expense Deleted.', 5000, 'success', 'top right');

            return $this->redirectRoute('expenses.index', navigate: true);
        }

        Flux::toast('', 'Expense Deleted.', 5000, 'success', 'top right');

        $this->dispatch('refreshComponent')->to('expenses.expense-index');
    }

    public function save()
    {
        //return with Error... splits needed if Project is SPLIT
        if ($this->split == true && empty($this->expense_splits)) {
            return $this->addError('no_splits', 'Splits required if Project is Split');
        }

        // Capture transaction ID before store/reset clears it
        $transactionId = $this->form->transaction?->id;

        $expense = $this->form->store($this->existing_check_id);
        
        // If user selected an existing check, recalculate check amount
        // (reimbursement deductions handled centrally)
        if ($this->existing_check_id) {
            Check::find($this->existing_check_id)?->recalculateAmount();
        }
        
        if (! $this->embedded) {
            $this->modal('expenses_form_modal')->close();
        }

        $this->toastExpenseSuccess($expense, 'Expense Created.');

        $this->resetModal();
        
        // Dispatch transaction removal (captured before resetModal cleared form)
        if ($transactionId) {
            $this->dispatch('transaction-used', transactionId: $transactionId);
        } else {
            $this->dispatch('refreshComponent')->to(ExpenseIndex::class);
        }
    }

    public function uploadReceipt(): void
    {
        $this->validate([
            'upload_file' => 'required|mimes:pdf,jpg,jpeg,png|max:20480',
        ], [
            'upload_file.required' => 'Please select a file to upload.',
            'upload_file.mimes' => 'File must be a PDF, JPG, or PNG.',
            'upload_file.max' => 'File must be less than 20MB.',
        ]);

        $docType = $this->upload_file->getClientOriginalExtension();
        $ocrFilename = date('Y-m-d-H-i-s') . '-' . rand(10, 99) . '.' . $docType;
        $ocrPath = '_temp_ocr/' . $ocrFilename;

        Storage::disk('files')->put($ocrPath, file_get_contents($this->upload_file->getRealPath()));

        $analyzerId = $this->upload_is_material_order
            ? config('services.azure_cu.analyzer_id_material_order')
            : null;

        $effectiveDocType = $this->upload_is_material_order ? 'material_order' : $docType;

        $ocrData = app(\App\Http\Controllers\ReceiptController::class)
            ->extractReceipt($ocrPath, $effectiveDocType, null, null, null, $analyzerId);

        if (isset($ocrData['error']) && $ocrData['error'] === true) {
            Storage::disk('files')->delete($ocrPath);
            $this->addError('upload_file', 'Unable to process receipt. Please check the file and try again.');
            return;
        }

        $fields = $ocrData['fields'] ?? [];

        // Match vendor from OCR merchant name
        $vendorId = null;
        $merchantName = $fields['merchant_name'] ?? null;
        if ($merchantName) {
            $vendors = Vendor::orderBy('business_name')->get(['id', 'business_name']);
            $matched = app(\App\Http\Controllers\CompanyEmailController::class)
                ->fuzzyMatchVendor($merchantName, $vendors, 70.0);
            if ($matched) {
                $vendorId = $matched->id;
            }
        }

        $hubVendorId = auth()->user()->vendor->id;

        $expense = Expense::create([
            'amount' => $fields['total'] ?? 0,
            'date' => $fields['transaction_date'] ?? now()->format('Y-m-d'),
            'invoice' => $fields['invoice_number'] ?? null,
            'vendor_id' => $vendorId,
            'belongs_to_vendor_id' => ($this->upload_is_material_order && $this->upload_belongs_to_vendor_id) ? $this->upload_belongs_to_vendor_id : $hubVendorId,
            'created_by_user_id' => auth()->user()->id,
        ]);

        // Save the receipt data
        app(\App\Http\Controllers\CompanyEmailController::class)
            ->saveExpenseReceipt($expense->id, $ocrData, $ocrFilename, null, false, $this->upload_is_material_order);

        // Clean up temp file
        Storage::disk('files')->delete($ocrPath);

        // Reset upload fields
        $this->upload_file = null;
        $this->upload_is_material_order = false;
        $this->upload_belongs_to_vendor_id = null;
        $this->modal('upload_receipt_modal')->close();

        // Set up edit state directly (avoid resetModal which close+show same-request conflict)
        $this->expense = $expense;
        $this->form->setExpense($expense);
        $this->view_text = [
            'card_title' => 'Update Expense',
            'button_text' => 'Update',
            'form_submit' => 'edit',
        ];
        if (! $this->embedded) {
            $this->modal('expenses_form_modal')->show();
        }

        $this->dispatch('refreshComponent')->to(ExpenseIndex::class);
    }

    public function render()
    {
        // $this->authorize('create', Expense::class);
        return view('livewire.expenses.form');
    }
}

<?php

namespace App\Livewire\Forms;

use App\Models\Check;
use App\Models\Distribution;
use App\Models\Expense;
use App\Models\ExpenseSplits;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\Vendor;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Validate;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Form;

class ExpenseForm extends Form
{
    use AuthorizesRequests;

    public ?Expense $expense;

    public $expense_transactions_sum = false;

    public $project_completed = false;

    public $receipts = false;

    #[Validate]
    public $split = false;

    #[Validate('required|numeric|regex:/^-?\d+(\.\d{1,2})?$/')]
    public $amount = null;

    #[Validate('required|date|before_or_equal:today|after:2017-01-01')]
    public $date = null;

    #[Validate('required')]
    public $vendor_id = null;

    #[Validate('required_unless:split,true')]
    public $project_id = null;

    #[Validate]
    public $reimbursment = null;

    #[Validate]
    public $invoice = null;

    #[Validate]
    public $note = null;

    #[Validate]
    public $paid_by = null;

    public bool $is_material_order = false;

    public $belongs_to_vendor_id = null;

    // // required_without:form.paid_by
    // #[Validate('nullable', as: 'bank account')]
    // public $bank_account_id = null;

    // #[Validate]
    public $merchant_name = null;

    // #[Validate]
    public $transaction = null;

    // #[Validate('sometimes|required_unless:reimbursment,null|mimes:jpeg,jpg,png,pdf')]
    //('required_if:reimbursment,Client')
    // #[Validate]
    public $receipt_file = null;

    public function rules()
    {
        return [];
    }

    protected $messages = [
        'amount.regex' => 'Amount format is incorrect. Format is 2145.36. No commas and only two digits after decimal allowed. If amount is under $1.00, use 00.XX',
        'project_id.required_unless' => 'Project is required unless Expense is Split.',
        'date.before_or_equal' => 'Date cannot be in the future. Make sure Date is before or equal to today.',
        'date.after' => 'Date cannot be before 2017. Make sure Date is after or equal to 01/01/2017.',
    ];

    public function setExpense(Expense $expense): void
    {
        $this->expense = $expense;

        // Populate receipt-derived hints when available
        $latestReceipt = $this->expense->relationLoaded('receipts')
            ? $this->expense->receipts->sortByDesc('created_at')->first()
            : $this->expense->receipts()->latest()->first();
        if ($latestReceipt) {
            $this->receipts = true;
            $this->is_material_order = (bool) $latestReceipt->is_material_order;
            if (is_array($latestReceipt->receipt_items) && isset($latestReceipt->receipt_items['merchant_name'])) {
                $this->merchant_name = $latestReceipt->receipt_items['merchant_name'];
            }
        }

        // Load belongs_to_vendor_id for material orders (when it differs from the hub)
        $hubVendorId = auth()->user()->vendor->id;
        if ($this->is_material_order && $expense->belongs_to_vendor_id != $hubVendorId) {
            $this->belongs_to_vendor_id = $expense->belongs_to_vendor_id;
        }

        $this->amount = $this->expense->amount;
        $this->date = $expense->date ? \Illuminate\Support\Carbon::parse($expense->date)->format('Y-m-d') : null;
        $this->vendor_id = $expense->vendor_id;

        // If distribution is set, encode project selector as "D:{id}"
        if (! empty($expense->distribution_id)) {
            $this->project_id = 'D:' . $expense->distribution_id;
        } else {
            $projectId = (int) ($expense->project_id ?? 0);
            $this->project_id = $projectId > 0 ? $projectId : null;
        }

        $this->reimbursment = $expense->reimbursment;
        $this->invoice = $expense->invoice;
        $this->note = $expense->note;
        $this->paid_by = $expense->paid_by;

        // Convenience flag used by the UI to indicate if transactions match the amount
        $transactionsSum = $this->expense->transactions->sum('amount');
        $this->expense_transactions_sum = ($transactionsSum == $this->expense->amount) && ($transactionsSum != '0.00');

        if (method_exists($this->component, 'clearCheckFields')) {
            $this->component->clearCheckFields();
        }

        $this->expense->loadMissing('check');
        $check = $this->expense->check;

        if (! $check) {
            return;
        }

        if (property_exists($this->component, 'bank_account_id')) {
            $this->component->bank_account_id = (string) $check->bank_account_id;
        }

        if (property_exists($this->component, 'check_type')) {
            $this->component->check_type = $check->check_type ?? '';
        }

        if (property_exists($this->component, 'check_number')) {
            $this->component->check_number = $check->check_type === 'Check'
                ? ($check->check_number !== null ? (string) $check->check_number : null)
                : null;
        }

        if (property_exists($this->component, 'next_check_auto')) {
            $this->component->next_check_auto = false;
        }

        if (property_exists($this->component, 'auto_check_number')) {
            $this->component->auto_check_number = null;
        }
    }

    public function expenseDetails()
    {
        if (is_numeric($this->project_id)) {
            $project_id = (int) $this->project_id;
            if ($project_id <= 0) {
                $project_id = null;
            }
            $distribution_id = null;
            $dist_user = null;
        } elseif (isset($this->project_id)) {
            $project_id = null;
            $distribution_id = substr((string) $this->project_id, 2);
            $dist_user = null;
        } elseif ($this->component->split) {
            $project_id = null;
            $distribution_id = null;
            $dist_user = null;
        } else {
            // Default when no project/distribution and not a split
            $project_id = null;
            $distribution_id = null;
            $dist_user = null;
        }

        return [
            'project_id' => $project_id,
            'distribution_id' => $distribution_id,
            'dist_user' => $dist_user,
        ];
    }

    public function save_splits(Expense $expense)
    {
        $expense_details = $this->expenseDetails();
        //if no splits / splits removed and project/distrubtuion entered...
        if (! $expense->splits->isEmpty() && (! is_null($expense_details['project_id']) || ! is_null($expense_details['distribution_id']))) {
            foreach ($expense->splits as $split_to_remove) {
                $split_to_remove = ExpenseSplits::findOrFail($split_to_remove->id);
                $split_to_remove->delete();
            }
        } else {
            foreach (collect($this->component->expense_splits) as $split) {
                if (is_numeric($split['project_id'])) {
                    $project_id = $split['project_id'];
                    $distribution_id = null;
                } else {
                    $project_id = null;
                    $distribution_id = substr($split['project_id'], 2);
                }

                if (isset($split['id'])) {
                    $update_split = ExpenseSplits::findOrFail($split['id']);
                    $update_split->update([
                        'amount' => $split['amount'],
                        'expense_id' => $expense->id,
                        'project_id' => $project_id,
                        'distribution_id' => $distribution_id,
                        'reimbursment' => isset($split['reimbursment']) ? $split['reimbursment'] : null,
                        'note' => isset($split['note']) ? $split['note'] : null,
                        'belongs_to_vendor_id' => auth()->user()->vendor->id,
                        'created_by_user_id' => auth()->user()->id,
                        'receipt_items' => (object) $split['items'],
                    ]);
                } else {
                    $split = ExpenseSplits::create([
                        'amount' => $split['amount'],
                        'expense_id' => $expense->id,
                        'project_id' => $project_id,
                        'distribution_id' => $distribution_id,
                        'reimbursment' => isset($split['reimbursment']) ? $split['reimbursment'] : null,
                        'note' => isset($split['note']) ? $split['note'] : null,
                        'belongs_to_vendor_id' => auth()->user()->vendor->id,
                        'created_by_user_id' => auth()->user()->id,
                        'receipt_items' => (object) $split['items'],
                    ]);
                }
            }
        }

        return;
    }

    public function delete()
    {
        // if ($this->transaction) {
        //     $this->transaction->delete();
        // }

        //CHECK
        // $check = $this->expense->check;

        // if($check){
        //     if($check->amount == $this->expense->amount){
        //         //if has transactions, remove
        //         $check->delete();
        //     }else{
        //         //edit check
        //     }
        // }
        //ASSOCIATED EXPENSES
        $associated_expenses = $this->expense->associated;
        foreach ($associated_expenses as $associated_expenses) {
            $associated_expenses->parent_expense_id = null;
            $associated_expenses->save();
        }

        //SPLITS
        $splits = $this->expense->splits;
        foreach ($splits as $split) {
            $split->delete();
        }

        //TRANSACTIONS
        $transactions = $this->expense->transactions;
        foreach ($transactions as $transaction) {
            $transaction->expense_id = null;
            $transaction->vendor_id = null;
            $transaction->save();
        }

        //RECEIPTS (soft delete)
        foreach ($this->expense->receipts as $receipt) {
            $receipt->delete();
        }

        //CHECK ASSOCIATIONS (detach pivot + clear legacy check_id)
        $this->expense->checks()->detach();
        if ($this->expense->check_id) {
            $this->expense->check_id = null;
            $this->expense->save();
        }

        $this->expense->delete();
    }

    public function update()
    {
        $this->authorize('create', Expense::class);
        $this->validate();

        $expense_details = $this->expenseDetails();

        // Save the original amount before updating
        $originalAmount = $this->expense->amount;

        $hubVendorId = auth()->user()->vendor->id;

        $this->expense->update([
            'amount' => $this->amount,
            'date' => $this->date,
            'invoice' => $this->invoice,
            'note' => $this->note,
            'project_id' => $expense_details['project_id'],
            'distribution_id' => $expense_details['distribution_id'],
            'vendor_id' => $this->vendor_id,
            'paid_by' => empty($this->paid_by) ? null : $this->paid_by,
            'reimbursment' => empty($this->reimbursment) ? null : $this->reimbursment,
            'belongs_to_vendor_id' => ($this->is_material_order && $this->belongs_to_vendor_id) ? $this->belongs_to_vendor_id : $hubVendorId,
            'created_by_user_id' => auth()->user()->id,
        ]);

        // Handle existing check
        $check = $this->expense->check;
        
        // Only create or update check when bank_account_id is set (required for a check)
        if (empty($this->paid_by) && 
            isset($this->component->bank_account_id) && 
            !empty($this->component->bank_account_id) && 
            isset($this->component->check_type)) {
            
            // Calculate distribution user ID if needed
            if ($expense_details['distribution_id']) {
                $distribution_user_id = Distribution::findOrFail($expense_details['distribution_id'])->user_id;
                $dist_user = ($distribution_user_id != 0) ? $distribution_user_id : null;
            } else {
                $dist_user = null;
            }

            // Look for an existing check with the same details
            $existing_check = null;
            if (!is_null($this->component->check_number)) {
                $existing_check = Check::where('deleted_at', null)
                    ->where('bank_account_id', $this->component->bank_account_id)
                    ->where('check_type', $this->component->check_type)
                    ->where('check_number', $this->component->check_number)
                    ->where('vendor_id', $this->vendor_id)
                    ->first();
            }

            // If there's an existing check with these details, update it
            if (isset($existing_check)) {
                // If this expense already had a different check, recalculate that check's amount
                if ($check && $check->id !== $existing_check->id) {
                    $check->amount = $check->expenses->sum('amount') + $check->timesheets->sum('amount');
                    $check->save();
                }
                
                $check = $existing_check;
                // Recalculate check amount from all linked expenses and timesheets
                $check->amount = $check->expenses->sum('amount') + $check->timesheets->sum('amount');
                $check->save();
            } 
            // Otherwise create a new check only if we have all required values
            elseif (isset($this->component->bank_account_id) && 
                    isset($this->component->check_type) && 
                    (!$check || 
                     $check->check_number != $this->component->check_number || 
                     $check->bank_account_id != $this->component->bank_account_id)) {
                
                // Create new check
                $check = Check::create([
                    'check_type' => $this->component->check_type,
                    'check_number' => $this->component->check_number,
                    'date' => $this->date,
                    'bank_account_id' => $this->component->bank_account_id,
                    'amount' => $this->amount,
                    'user_id' => $dist_user,
                    'vendor_id' => $this->vendor_id,
                    'belongs_to_vendor_id' => auth()->user()->vendor->id,
                    'created_by_user_id' => auth()->user()->id,
                ]);
            }
            
            // Update the expense with the new check_id
            if (isset($check)) {
                $this->expense->update([
                    'check_id' => $check->id,
                ]);
            }
        } else if ($check) {
            // If there's no bank account or check details but there was a check previously,
            // detach the check from this expense and recalculate check amount
            $this->expense->update([
                'check_id' => null
            ]);
            
            // Recalculate check amount from remaining linked expenses and timesheets
            $check->amount = $check->expenses->sum('amount') + $check->timesheets->sum('amount');
            $check->save();
        }

        $this->save_splits($this->expense);

        $this->attachCrossVendorRelationships($this->expense);

        if ($this->receipt_file) {
            $receipt_success = $this->upload_receipt_file($this->expense->amount, $this->expense->id);

            if (!$receipt_success) {
                session()->flash('warning', 'Expense updated but receipt could not be processed. Please try uploading the receipt again.');
            }
        }

        return $this->expense;
    }

    public function store($existing_check_id = null)
    {
        $this->authorize('create', Expense::class);
        $this->validate();

        $expense_details = $this->expenseDetails();        

        // Determine if we should create/reuse a check
        // Skip check creation if attaching to an existing check
        $shouldCreateCheck = empty($this->paid_by) && !$existing_check_id;
        
        // Prefer component-level fields set by the component; fallback to transaction
        $bankAccountId = $this->component->bank_account_id ?? null;
        $checkType = $this->component->check_type ?? null;
        $checkNumber = $this->component->check_number ?? null;

        // Fallback from transaction if component fields are missing
        if (!$bankAccountId && $this->transaction?->bank_account_id) {
            $bankAccountId = $this->transaction->bank_account_id;
        }

        if (!$checkType && $this->transaction?->check_number) {
            if ($this->transaction->check_number === '1010101') {
                $checkType = 'Transfer';
            } elseif ($this->transaction->check_number === '2020202') {
                $checkType = 'Cash';
            } else {
                $checkType = 'Check';
            }
        }

        if ($checkType === 'Check' && !$checkNumber && $this->transaction?->check_number && !in_array($this->transaction->check_number, ['1010101','2020202'])) {
            $checkNumber = $this->transaction->check_number;
        }

        // Validate and create/reuse check when applicable
        if ($shouldCreateCheck && $bankAccountId && $checkType) {
            // Calculate distribution user if distribution selected
            if ($expense_details['distribution_id']) {
                $distribution_user_id = Distribution::findOrFail($expense_details['distribution_id'])->user_id;
                $dist_user = ($distribution_user_id != 0) ? $distribution_user_id : null;
            } else {
                $dist_user = null;
            }

            $check = null;
            $existing_check = null;

            // Only attempt dedupe on paper checks with a specific check number
            if ($checkType === 'Check' && !is_null($checkNumber)) {
                $existing_check = Check::whereNull('deleted_at')
                    ->where('bank_account_id', $bankAccountId)
                    ->where('check_type', $checkType)
                    ->where('check_number', $checkNumber)
                    ->where('vendor_id', $this->vendor_id)
                    ->first();
            }

            if ($existing_check) {
                $check = $existing_check;
                // Recalculate check amount from all linked expenses and timesheets
                $check->amount = $check->expenses->sum('amount') + $check->timesheets->sum('amount');
                $check->save();
                
            } else {
                $check = Check::create([
                    'check_type' => $checkType,
                    'check_number' => $checkNumber, // may be null for non-paper types
                    'date' => $this->date,
                    'bank_account_id' => $bankAccountId,
                    'amount' => $this->amount,
                    'user_id' => $dist_user,
                    'vendor_id' => $this->vendor_id,
                    'belongs_to_vendor_id' => auth()->user()->vendor->id,
                    'created_by_user_id' => auth()->user()->id,
                ]);
                
            }
        } else {
            $check = null;
        }
        
        // If attaching to existing check, use that check_id
        if ($existing_check_id) {
            $check = Check::find($existing_check_id);
        }

        $expense = Expense::create([
            'amount' => $this->amount,
            'date' => $this->date,
            'invoice' => $this->invoice,
            'note' => $this->note,
            //if $split true, project_id = NULL || if expense_splits isset/true, project_id by default is NULL as expected.
            'project_id' => $expense_details['project_id'],
            'distribution_id' => $expense_details['distribution_id'],
            'vendor_id' => $this->vendor_id,
            'check_id' => ! isset($check) ? null : $check->id,
            'paid_by' => empty($this->paid_by) ? null : $this->paid_by,
            'reimbursment' => empty($this->reimbursment) ? null : $this->reimbursment,
            'belongs_to_vendor_id' => ($this->is_material_order && $this->belongs_to_vendor_id) ? $this->belongs_to_vendor_id : auth()->user()->vendor->id,
            'created_by_user_id' => auth()->user()->id,
        ]);

        

        if ($this->transaction) {
            // Determine final check association for the transaction.
            $finalCheckId = isset($check)
                ? $check->id
                : ($this->transaction->check_id ?? null);

            if ($finalCheckId) {
                // Prefer linking transaction to a check; do not also link directly to the expense.
                $this->transaction->check_id = $finalCheckId;
                $this->transaction->expense_id = null;
            } else {
                // Only when no check is involved, associate the transaction directly to the expense.
                $this->transaction->expense_id = $expense->id;
            }

            if (isset($this->vendor_id)) {
                $authVendorId = auth()->user()->vendor->id ?? null;
                // Only set the transaction vendor when it's different from the auth vendor
                if ($authVendorId === null || (int) $this->vendor_id !== (int) $authVendorId) {
                    $this->transaction->vendor_id = $this->vendor_id;
                }
            }

            $this->transaction->save();
        }

        if ($this->receipt_file) {
            $receipt_success = $this->upload_receipt_file($expense->amount, $expense->id);

            if (!$receipt_success) {
                // Handle the failure - expense is already saved, just inform user
                session()->flash('warning', 'Expense saved but receipt could not be processed. Please try uploading the receipt again.');
            }
        }

        $this->attachCrossVendorRelationships($expense);

        return $expense;
    }

    public function upload_receipt_file($expense_amount, $expense_id)
    {
        $doc_type = $this->receipt_file->getClientOriginalExtension();

        $ocr_filename = date('Y-m-d-H-i-s').'-'.rand(10, 99).'.'.$doc_type;
        $ocr_path = '_temp_ocr/'.$ocr_filename;

        // Store the file using Storage facade
        Storage::disk('files')->put($ocr_path, file_get_contents($this->receipt_file->getRealPath()));

        // OCR via unified extractReceipt()
        $analyzerId = $this->is_material_order
            ? config('services.azure_cu.analyzer_id_material_order')
            : null;
        $effectiveDocType = $this->is_material_order ? 'material_order' : $doc_type;
        $ocr_receipt_data = app(\App\Http\Controllers\ReceiptController::class)->extractReceipt($ocr_path, $effectiveDocType, $expense_amount, null, null, $analyzerId);

        // Check if OCR extraction failed
        if (isset($ocr_receipt_data['error']) && $ocr_receipt_data['error'] === true) {
            // Log the error
            Log::error('OCR extraction failed for expense', [
                'expense_id' => $expense_id,
                'filename' => $ocr_filename,
                'expense_amount' => $expense_amount,
                'ocr_path' => $ocr_path,
            ]);

            // Clean up the temporary file
            Storage::disk('files')->delete($ocr_path);

            // Reset the receipt file
            $this->receipt_file = null;

            // Add error message for the user
            $this->addError('receipt_file', 'Unable to process receipt. Please check the file and try again.');

            return false;
        }

        //ATTACHMENT - only proceed if OCR was successful
        app(\App\Http\Controllers\CompanyEmailController::class)->saveExpenseReceipt($expense_id, $ocr_receipt_data, $ocr_filename, null, false, $this->is_material_order);

        // Clean up temp file
        Storage::disk('files')->delete($ocr_path);

        $this->receipt_file = null;

        return true;
    }

    private function attachCrossVendorRelationships(Expense $expense): void
    {
        $hubVendorId = auth()->user()->vendor->id;
        $ownerVendorId = $expense->belongs_to_vendor_id;

        if ((int) $ownerVendorId === (int) $hubVendorId) {
            return;
        }

        $ownerVendor = Vendor::withoutGlobalScopes()->find($ownerVendorId);
        if (! $ownerVendor) {
            return;
        }

        // Attach the expense vendor (e.g. Home Depot) to the owner's vendor list
        if ($expense->vendor_id && ! $ownerVendor->vendors()->where('vendor_id', $expense->vendor_id)->exists()) {
            $ownerVendor->vendors()->attach($expense->vendor_id);
        }

        // Attach the project to the owner vendor with Invited status
        if ($expense->project_id) {
            $project = Project::withoutGlobalScopes()->find($expense->project_id);

            if ($project && ! $project->vendors()->withoutGlobalScopes()->where('vendor_id', $ownerVendorId)->exists()) {
                $project->vendors()->withoutGlobalScopes()->attach($ownerVendorId, ['client_id' => $project->client_id]);

                if ($project->client_id && ! DB::table('client_vendor')->where('client_id', $project->client_id)->where('vendor_id', $ownerVendorId)->exists()) {
                    DB::table('client_vendor')->insert([
                        'client_id' => $project->client_id,
                        'vendor_id' => $ownerVendorId,
                        'source' => 'auto-attach',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                ProjectStatus::create([
                    'project_id' => $project->id,
                    'belongs_to_vendor_id' => $ownerVendorId,
                    'status_code' => ProjectStatus::getCodeForLabel('Invited') ?? 1,
                    'start_date' => today()->format('Y-m-d'),
                ]);
            }
        }
    }
}

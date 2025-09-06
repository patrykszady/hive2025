<?php

namespace App\Livewire\Forms;

use App\Models\Check;
use App\Models\Distribution;
use App\Models\Expense;
use App\Models\ExpenseSplits;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
// use Livewire\Attributes\Rule;
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

    protected $messages =
        [
            'amount.regex' => 'Amount format is incorrect. Format is 2145.36. No commas and only two digits after decimal allowed. If amount is under $1.00, use 00.XX',
            'project_id.required_unless' => 'Project is required unless Expense is Split.',
            'date.before_or_equal' => 'Date cannot be in the future. Make sure Date is before or equal to today.',
            'date.after' => 'Date cannot be before 2017. Make sure Date is after or equal to 01/01/2017.',
            'receipt_file.required_if' => 'Receipt is required if Expense is Reimbursed or has Splits',
        ];

    public function setExpense($expense)
    {
        $this->expense = $expense;

        if ($this->expense->receipts) {
            $receipt = $this->expense->receipts()->latest()->first();

            if (! is_null($receipt)) {
                $this->receipts = true;
                $this->note = $receipt->note;
                // if(!is_null($receipt->receipt_html)){
                // if(isset($receipt->receipt_items->handwritten_notes)){
                //     $this->handwritten = implode(", ", $receipt->receipt_items->handwritten_notes);
                // }

                // if(isset($receipt->receipt_items->purchase_order)){
                //     $this->purchase_order = $receipt->receipt_items->purchase_order;
                // }

                if (isset($receipt->receipt_items->merchant_name)) {
                    $this->merchant_name = $receipt->receipt_items->merchant_name;
                }
            }
        }

        $this->amount = $this->expense->amount;
        $this->date = $expense->date->format('Y-m-d');
        $this->vendor_id = $expense->vendor_id;

        // 8-29-23 this can go into Expense model... getter ... get
        if ($expense->distribution_id) {
            $this->project_id = 'D:'.$expense->distribution_id;
        } else {
            $this->project_id = $expense->project_id;
            // dd($this->project_id);
            // //if existing project is not SPLIT
            // if (! is_null($this->project_id) && $this->project_id != 0) {
            //     $project_title = $this->component->projects->where('id', $this->project_id)->first()->last_status->title;
            //     if ($project_title == 'Complete') {
            //         $this->project_completed = true;
            //     }
            // }
        }

        $this->reimbursment = $expense->reimbursment;
        $this->invoice = $expense->invoice;
        $this->note = $expense->note;
        $this->paid_by = $expense->paid_by;

        if ($this->expense->check) {
            $this->bank_account_id = $this->expense->check->bank_account_id;
            $this->check_type = $this->expense->check->check_type;
            $this->check_number = $this->expense->check->check_number;
            $this->transaction = true;
            // if(!$this->expense->check->transactions->isEmpty()){
            //     $this->transaction = TRUE;
            // }
        }

        //09-05-2023 need to get the file extention here... not a boolen
        // $this->receipt_file = $this->expense->receipts()->exists();

        $this->expense_transactions_sum = $this->expense->transactions->sum('amount') == $this->expense->amount && $this->expense->transactions->sum('amount') != '0.00' ? true : false;
    }

    public function expenseDetails()
    {
        if (is_numeric($this->project_id)) {
            $project_id = $this->project_id;
            $distribution_id = null;
            $dist_user = null;
        }elseif(isset($this->project_id)){
            $project_id = null;
            $distribution_id = substr($this->project_id, 2);
            $dist_user = null;
        } elseif ($this->component->split) {
            $project_id = null;
            $distribution_id = null;
            $dist_user = null;
        } else {
            Log::error('ExpenseForm expenseDetails error', [
                'expense' => $this->expense,
                'project_id' => $this->project_id,
                'vendor_id' => $this->vendor_id,
            ]);

            dd('in else');

            // } elseif (is_null($this->project_id)) {
            // dd('in elseif');
            // $project_id = null;
            // $distribution_id = null;
            // $dist_user = $this->vendor_id;

            //for checks
            // $distribution = Distribution::findOrFail($distribution_id)->user_id;
            // if($distribution != 0){
            //     $dist_user = $distribution;
            // }else{
            //     $dist_user = NULL;
            // }
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
        if ($this->transaction) {
            $this->transaction->delete();
        } else {
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
                $transaction->save();
            }

            //RECEIPTS
            $this->expense->receipts()->delete();

            $this->expense->delete();
        }
    }

    public function update()
    {
        $this->authorize('create', Expense::class);
        $this->validate();

        $expense_details = $this->expenseDetails();

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
                // If this expense already had a different check, adjust that check's amount
                if ($check && $check->id !== $existing_check->id) {
                    $check->amount = $check->amount - $this->amount;
                    $check->save();
                }
                
                $check = $existing_check;
                $check->amount = $check->amount + $this->amount;
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
            // detach the check from this expense
            $check->amount = $check->amount - $this->amount;
            $check->save();
            
            $this->expense->update([
                'check_id' => null
            ]);
        }

        $this->save_splits($this->expense);

        if ($this->receipt_file) {
            $receipt_success = $this->upload_receipt_file($this->expense->amount, $this->expense->id);

            if (!$receipt_success) {
                session()->flash('warning', 'Expense updated but receipt could not be processed. Please try uploading the receipt again.');
            }
        }

        return $this->expense;
    }

    public function store()
    {
        $this->authorize('create', Expense::class);
        $this->validate();

        $expense_details = $this->expenseDetails();

        //validate check...
        if (empty($this->paid_by) && isset($this->bank_account_id)) {
            if ($expense_details['distribution_id']) {
                $distribution_user_id = Distribution::findOrFail($expense_details['distribution_id'])->user_id;
                if ($distribution_user_id != 0) {
                    $dist_user = $distribution_user_id;
                } else {
                    $dist_user = null;
                }
            } else {
                $dist_user = null;
            }

            if (!is_null($this->check_number)) {
                $existing_check = Check::where('deleted_at', null)
                    ->where('bank_account_id', $this->bank_account_id)
                    ->where('check_type', $this->check_type)
                    ->where('check_number', $this->check_number)
                    ->where('vendor_id', $this->vendor_id)
                    ->first();
            }

            if (isset($existing_check)) {
                $check = $existing_check;
                $check->amount = $check->amount + $this->amount;
                $check->save();
            } else {
                $check = Check::create([
                    'check_type' => $this->check_type,
                    'check_number' => $this->check_number,
                    'date' => $this->date,
                    'bank_account_id' => $this->bank_account_id,
                    'amount' => $this->amount,
                    //user_id if expense project = distribution
                    'user_id' => $dist_user,
                    'vendor_id' => $this->vendor_id,
                    'belongs_to_vendor_id' => auth()->user()->vendor->id,
                    'created_by_user_id' => auth()->user()->id,
                ]);
            }
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
            'belongs_to_vendor_id' => auth()->user()->vendor->id,
            'created_by_user_id' => auth()->user()->id,
        ]);

        if ($this->transaction) {
            $this->transaction->check_id = isset($check) ? $check->id : null;
            $this->transaction->expense_id = isset($expense) ? $expense->id : null;
            $this->transaction->vendor_id = isset($this->vendor_id) ? $this->vendor_id : null;
            $this->transaction->save();
        }

        if ($this->receipt_file) {
            $receipt_success = $this->upload_receipt_file($expense->amount, $expense->id);

            if (!$receipt_success) {
                // Handle the failure - expense is already saved, just inform user
                session()->flash('warning', 'Expense saved but receipt could not be processed. Please try uploading the receipt again.');
            }
        }

        return $expense;
    }

    public function upload_receipt_file($expense_amount, $expense_id)
    {
        $doc_type = $this->receipt_file->getClientOriginalExtension();

        $ocr_filename = date('Y-m-d-H-i-s').'-'.rand(10, 99).'.'.$doc_type;
        $ocr_path = '_temp_ocr/'.$ocr_filename;

        // Store the file using Storage facade
        Storage::disk('files')->put($ocr_path, file_get_contents($this->receipt_file->getRealPath()));

        // Pass the path in the format that azure_document_model expects (with 'files/' prefix)
        $azure_path = 'files/' . $ocr_path;
        $document_model = app(\App\Http\Controllers\ReceiptController::class)->azure_document_model($doc_type, $azure_path);

        //send to ReceiptController@azure_receipts
        $ocr_receipt_extracted = app(\App\Http\Controllers\ReceiptController::class)->azure_receipts($ocr_path, $doc_type, $document_model);
        //pass receipt info to ocr_extract method
        $ocr_receipt_data = app(\App\Http\Controllers\ReceiptController::class)->ocr_extract($ocr_receipt_extracted, $expense_amount);

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
        app(\App\Http\Controllers\CompanyEmailController::class)->saveExpenseReceipt($expense_id, $ocr_receipt_data, $ocr_filename);

        // Clean up temp file
        Storage::disk('files')->delete($ocr_path);

        $this->receipt_file = null;

        return true;
    }
}

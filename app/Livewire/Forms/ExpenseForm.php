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

    // required_without:form.paid_by
    #[Validate('nullable', as: 'bank account')]
    public $bank_account_id = null;

    #[Validate('required_with:bank_account_id', as: 'type')]
    public $check_type = null;

    // #[Validate('required_if:check_type,Check')]
    public $check_number = null;

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
        return [
            'check_number' => [
                'required_if:check_type,Check',
                'nullable',
                'numeric',

                //ignore if vendor_id of Check is same as request()->vendor_id
                // ->ignore($this->check),
                Rule::unique('checks', 'check_number')->where(function ($query) {
                    //->where('vendor_id', '!=', $this->vendor_id)

                    //where per vendor bank_account ... all bank accounts that have the inst ID
                    return $query->where('deleted_at', null)->where('bank_account_id', $this->bank_account_id)->where('vendor_id', '!=', $this->vendor_id);
                }),
                // ->ignore($this->check),
            ],
        ];
    }
    // public function rules()
    // {
    //     return [
    //         'project_id' => 'required_unless:split,true',

    //         'merchant_name' => 'nullable',

    //         'transaction' => 'nullable',
    //         // 'reimbursment' => [
    //         //     Rule::requiredIf(function(){
    //         //         //client_reimbursement
    //         //         // dd($this->reimbursment == 'client_reimbursement');
    //         //         // dd(Project::findOrFail($this->project_id)->project_status->title == "Complete");
    //         //         $title = Project::findOrFail($this->project_id)->project_status->title;

    //         //         // return $title == 'Complete' && $this->reimbursment == 'client_reimbursement' ? false : true;
    //         //         if($title == 'Complete' && $this->reimbursment == 'client_reimbursement'){
    //         //             Rule::notIn(['client_reimbursement']);
    //         //         }else{
    //         //             //false = continue. true = validation error!
    //         //             return false;
    //         //             // || $this->split == true
    //         //             // return $this->reimbursment != NULL ? true : false;
    //         //         }
    //         //     }),
    //         //     // 'nullable',
    //         //     // 'mimes:jpeg,jpg,png,pdf'
    //         //     ],

    //         'receipt_file' => [
    //             Rule::requiredIf(function(){
    //                 if($this->receipts != FALSE){
    //                     return false;
    //                 }else{
    //                     // || $this->split == true
    //                     return $this->reimbursment != NULL && !is_numeric($this->reimbursment) ? true : false;
    //                 }
    //             }),
    //             'nullable',
    //             'mimes:jpeg,jpg,pdf,png'
    //             ],
    //     ];
    // }

    // $this->form->reimbursment
    // if($value == 'Client'){
    //     $project = Project::findOrFail($this->form->project_id);
    //     // dd($project);
    //     //return with validation error for Reimbursment ... no Client Reimbursment allowed if Project_status = Complete
    //     //$this->reimbursment
    //     if($value == 'Client' && $project->project_status->title == 'Complete'){
    //         // errorBag reimbursment = "Cannot add Expense as Reimbursment for a project already Completed."
    //         $this->addError('testtest', 'Cannot add Expense as Reimbursment for a project already Completed.');
    //     }
    // }

    //     return [

    //         'amount_disabled' => 'nullable',

    //         //USED in MULTIPLE OF PLACES TimesheetPaymentForm and VendorPaymentForm
    //         //required_without:check.paid_by
    //         'check.bank_account_id' => 'nullable',
    //         'check.check_type' => 'required_with:check.bank_account_id',
    //         // 'check.check_number' => 'required_if:check.check_type,Check',
    //         //check_number is unique on Checks table where bank_account_id and check_number must be unique
    //         //02-21-2023 - used in MILTIPLE of places... VendorPaymentForm...
    //         'check.check_number' => [
    //             //ignore if vendor_id of Check is same as request()->vendor_id
    //             'required_if:check.check_type,Check',
    //             'nullable',
    //             'numeric',
    //             Rule::unique('checks', 'check_number')->where(function ($query) {
    //                 return $query->where('deleted_at', NULL)->where('bank_account_id', $this->check->bank_account_id)->where('vendor_id', '!=', $this->expense->vendor_id);
    //             }),
    //             //->ignore(request()->get('check_id_id'))
    //         ],
    //         'via_vendor_employees' => 'nullable',
    //     ];

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
            //if existing project is not SPLIT
            if (! is_null($this->project_id) && $this->project_id != 0) {
                $project_title = $this->component->projects->where('id', $this->project_id)->first()->last_status->title;
                if ($project_title == 'Complete') {
                    $this->project_completed = true;
                }
            }
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
        } elseif ($this->component->splits) {
            $project_id = null;
            $distribution_id = null;
            $dist_user = null;
        } elseif (is_null($this->project_id)) {
            dd('in elseif');
            $project_id = null;
            $distribution_id = null;
            $dist_user = $this->vendor_id;
        } else {
            $project_id = null;
            $distribution_id = substr($this->project_id, 2);
            $dist_user = null;

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
                        'belongs_to_vendor_id' => auth()->user()->primary_vendor_id,
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
                        'belongs_to_vendor_id' => auth()->user()->primary_vendor_id,
                        'created_by_user_id' => auth()->user()->id,
                        'receipt_items' => (object) $split['items'],
                    ]);
                }
            }
        }
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
            //if $split true, project_id = NULL || if expense_splits isset/true, project_id by default is NULL as expected.
            'project_id' => $expense_details['project_id'],
            'distribution_id' => $expense_details['distribution_id'],
            'vendor_id' => $this->vendor_id,
            // 'check_id' => $check_id,
            'paid_by' => empty($this->paid_by) ? null : $this->paid_by,
            'reimbursment' => $this->reimbursment,
            // 'belongs_to_vendor_id' => $vendor->id,
            'created_by_user_id' => auth()->user()->id,
        ]);

        //check...
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

            $existing_check = Check::where('deleted_at', null)->where('check_type', 'Check')->where('bank_account_id', $this->bank_account_id)->where('check_number', $this->check_number)->where('vendor_id', $this->vendor_id)->first();

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
                    'belongs_to_vendor_id' => auth()->user()->primary_vendor_id,
                    'created_by_user_id' => auth()->user()->id,
                ]);
            }

            $this->expense->update([
                'check_id' => $check->id,
            ]);
        }

        $this->save_splits($this->expense);

        if ($this->receipt_file) {
            $this->upload_receipt_file($this->expense->amount, $this->expense->id);
        }

        return $this->expense;
    }

    public function store()
    {
        $this->authorize('create', Expense::class);
        $this->validate();
        //validate check...
        $expense_details = $this->expenseDetails();
        // dd($this);
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

            $existing_check = Check::where('deleted_at', null)->where('check_type', 'Check')->where('bank_account_id', $this->bank_account_id)->where('check_number', $this->check_number)->where('vendor_id', $this->vendor_id)->first();

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
                    'belongs_to_vendor_id' => auth()->user()->primary_vendor_id,
                    'created_by_user_id' => auth()->user()->id,
                ]);
            }
        }

        // $expense = Expense::create($this->only(['amount', 'date', 'vendor_id', 'project_id', 'reimbursment', 'invoice', 'note', 'paid_by']));
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
            'reimbursment' => $this->reimbursment,
            'belongs_to_vendor_id' => auth()->user()->vendor->id,
            'created_by_user_id' => auth()->user()->id,
        ]);

        if ($this->transaction) {
            $this->transaction->check_id = isset($check) ? $check->id : null;
            $this->transaction->expense_id = isset($expense) ? $expense->id : null;
            $this->transaction->save();
        }

        if ($this->receipt_file) {
            $this->upload_receipt_file($expense->amount, $expense->id);
        }

        return $expense;
    }

    public function upload_receipt_file($expense_amount, $expense_id)
    {
        $doc_type = $this->receipt_file->getClientOriginalExtension();

        $ocr_filename = date('Y-m-d-H-i-s').'-'.rand(10, 99).'.'.$doc_type;
        $ocr_path = 'files/_temp_ocr/'.$ocr_filename;
        $this->receipt_file->storeAs('_temp_ocr', $ocr_filename, 'files');

        $document_model = app(\App\Http\Controllers\ReceiptController::class)->azure_document_model($doc_type, $ocr_path);

        //send to ReceiptController@azure_receipts with $location and $document_model
        $ocr_receipt_extracted = app(\App\Http\Controllers\ReceiptController::class)->azure_receipts($ocr_path, $doc_type, $document_model);
        //pass receipt info to ocr_extract method
        $ocr_receipt_data = app(\App\Http\Controllers\ReceiptController::class)->ocr_extract($ocr_receipt_extracted, $expense_amount);

        if (is_null($ocr_receipt_data['fields']['transaction_date'])) {
            //send to ReceiptController@azure_receipts with $location and $document_model
            if ($document_model === 'prebuilt-invoice') {
                $document_model = 'prebuilt-receipt';
            } else {
                $document_model = 'prebuilt-invoice';
            }

            $ocr_receipt_extracted = app(\App\Http\Controllers\ReceiptController::class)->azure_receipts($ocr_path, $doc_type, $document_model);
            //pass receipt info to ocr_extract method
            $ocr_receipt_data = app(\App\Http\Controllers\ReceiptController::class)->ocr_extract($ocr_receipt_extracted, $expense_amount);
        }

        //ATTACHMENT
        //send to ReceiptController@add_attachments_to_expense
        app(\App\Http\Controllers\ReceiptController::class)->add_attachments_to_expense($expense_id, null, $ocr_receipt_data, $ocr_filename);

        $this->receipt_file = null;
    }
}

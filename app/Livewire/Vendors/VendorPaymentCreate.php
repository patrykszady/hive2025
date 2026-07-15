<?php

namespace App\Livewire\Vendors;

use App\Jobs\SendVendorPaymentEmailJob;
use App\Livewire\Forms\VendorPaymentForm;
use App\Traits\HandlesChecks;

use App\Models\BankAccount;
use App\Models\Check;
use App\Models\Expense;
use App\Models\Project;
use App\Models\Vendor;
use Carbon\Carbon;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

class VendorPaymentCreate extends Component
{
    use AuthorizesRequests, HandlesChecks;

    public VendorPaymentForm $form;

    public Vendor $vendor;

    public $project_id = '';

    /**
     * Projects keyed by id as plain arrays so UI state (show, disabled, order, amounts) persists across requests.
     * [ project_id => [ 'id'=>int, 'address'=>string, 'project_name'=>string, 'show'=>bool, 'disabled'=>bool, 'order'=>int,
     *   'vendor_expenses_sum'=>float, 'vendor_bids_sum'=>float, 'balance'=>float, 'amount'=>float|null ] ]
     */
    public array $projects = [];

    public $employees = [];

    /**
     * Unsettled expenses the company paid on this vendor's behalf
     * (reimbursment = 'V:{vendor_id}') — selectable deductions from the payment,
     * mirroring the timesheet payment reimbursement card.
     */
    public $vendor_reimbursement_expenses = [];

    /** Selection state keyed by expense id (same idiom as timesheet payments). */
    public array $selectedVendorReimbursementExpenses = [];

    public $payment_projects_count = 0;

    public $saved_expenses = [];

    // Backing field for custom validation to enforce check total > $0
    public $check_total_min = null;

    public $view_text = [
        'card_title' => 'Create Vendor Payments',
        'button_text' => 'Create Vendor Check',
        'form_submit' => 'confirmPayment',
    ];

    protected $listeners = ['updateProjectBids'];

    protected function messages(): array
    {
        return $this->handlesChecksMessages();
    }

    protected function rules(): array
    {
        return $this->componentMergedRules(
            $this->form->rules(),
            [
                'project_id' => 'nullable',
                'projects.*.order' => 'nullable',
                'projects.*.disabled' => 'nullable',
                'projects.*.show' => 'nullable',
                'projects.*.vendor_expenses_sum' => 'nullable',
                'projects.*.vendor_bids_sum' => 'nullable',
                'projects.*.balance' => 'nullable',
                'projects.*.amount' => 'nullable|numeric|min:0.01|regex:/^-?\d+(\.\d{1,2})?$/',
                'selectedVendorReimbursementExpenses.*' => 'nullable|boolean',
                // Validate that at least one project has a positive amount, and the total is > 0
                'check_total_min' => [function (string $attribute, $value, \Closure $fail): void {
                    if ($this->getVendorCheckSumProperty() <= 0) {
                        $fail('Check total needs to be greater than $0 and include at least 1 project.');
                    }
                }],
            ]
        );
    }

    public function mount()
    {
        //09-05-2023 if proejct not active ...add in dropdown
        $projectsCollection = Project::where('created_at', '>', Carbon::now()->subYears(2)->format('Y-m-d'))
            ->status([6, 7, 8]) // Active, Complete, Service Call
            ->with('latestStatus')
            ->get()
            ->sortBy([
                ['latestStatus.status_code', 'asc'],
                ['latestStatus.start_date', 'desc'],
            ]);

        $order = 0;
        $this->projects = $projectsCollection->mapWithKeys(function ($p) use (&$order) {
            $expensesSum = $p->expenses()->where('vendor_id', $this->vendor->id)->sum('amount');
            $bidsSum = $p->bids()->vendorBids($this->vendor->id)->sum('amount');
            $balance = $bidsSum - $expensesSum;
            $hasOutstandingBalance = $balance > 0;

            if ($hasOutstandingBalance) {
                $order++;
            }

            return [
                $p->id => [
                    'id' => $p->id,
                    'address' => $p->address,
                    'project_name' => $p->project_name,
                    'show' => $hasOutstandingBalance,
                    'disabled' => $hasOutstandingBalance,
                    'order' => $hasOutstandingBalance ? $order : 0,
                    'vendor_expenses_sum' => (float) $expensesSum,
                    'vendor_bids_sum' => (float) $bidsSum,
                    'balance' => (float) $balance,
                    'amount' => null,
                ],
            ];
        })->toArray();

        $this->payment_projects_count = $order;
        // Date will be set by browser's local date via Alpine.js
        $this->employees = auth()->user()->vendor->users()->where('is_employed', 1)->get();

        // Unsettled reimbursements this vendor owes the company — deducted from
        // the payment when selected. Same "unsettled = no check yet" rule as
        // timesheet payments; default unselected.
        $this->vendor_reimbursement_expenses =
            Expense::where('reimbursment', 'V:'.$this->vendor->id)
                ->whereNull('paid_by')
                ->whereNull('check_id')
                ->orderBy('date', 'DESC')
                ->get();
        $this->selectedVendorReimbursementExpenses = $this->vendor_reimbursement_expenses
            ->pluck('id')
            ->mapWithKeys(fn ($id) => [$id => false])
            ->toArray();
        if ($this->vendor_reimbursement_expenses->isEmpty()) {
            $this->vendor_reimbursement_expenses = collect();
        }
    }

    #[Computed]
    public function availableProjects()
    {
        $projectIds = collect($this->projects)->where('disabled', false)->pluck('id');
        
        return Project::whereIn('id', $projectIds)
            ->with('latestStatus')
            ->get()
            ->sortBy([
                ['latestStatus.status_code', 'asc'],
                ['latestStatus.start_date', 'desc'],
            ]);
    }

    /**
     * Handle updates to form fields
     */
    public function updated($field, $value)
    {
        // Keep validation errors visible but don't let the exception skip the
        // render — the check-image preview must still update (e.g. an
        // "already been taken" check number that has a scanned image to show).
        try {
            // Call trait method to handle check-related updates
            $this->handleChecksUpdated($field, $value);

            // Check if a project amount was updated
            if (preg_match('/^projects\.(\d+)\.amount$/', $field, $matches)) {
                $project_id = $matches[1];
                $this->updateProjectBalance($project_id);
                // Re-validate check total as amounts change
                $this->validateOnly('check_total_min');
            }

            // Reimbursement selections change the check total too
            if (str_starts_with($field, 'selectedVendorReimbursementExpenses.')) {
                // Deductions settle only through a check — clear a Paid By
                // chosen BEFORE the reimbursement was ticked (the select's
                // options are only disabled going forward).
                if ($value && $this->form->paid_by) {
                    $this->form->paid_by = null;
                    $this->form->invoice = null;
                }
                $this->validateOnly('check_total_min');
            }

            $this->validateOnly($field);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->setErrorBag($e->validator->errors());
        }
    }

    public function addProject()
    {
        $this->validateOnly('project_id');

    $id = (int) $this->project_id;
    if (!isset($this->projects[$id])) { return; }
    $expensesSum = Project::find($id)?->expenses()->where('vendor_id', $this->vendor->id)->sum('amount') ?? 0;
    $bidsSum = Project::find($id)?->bids()->vendorBids($this->vendor->id)->sum('amount') ?? 0;
    $this->projects[$id]['show'] = true;
    $this->projects[$id]['disabled'] = true;
    $this->projects[$id]['order'] = $this->payment_projects_count++;
    $this->projects[$id]['vendor_expenses_sum'] = (float) $expensesSum;
    $this->projects[$id]['vendor_bids_sum'] = (float) $bidsSum;
    $this->projects[$id]['balance'] = (float) ($bidsSum - $expensesSum);

        $this->project_id = '';
    }

    public function updateProjectBids($project_id)
    {
    $id = (int) $project_id;
    if (!isset($this->projects[$id])) { return; }
    $bidsSum = Project::find($id)?->bids()->vendorBids($this->vendor->id)->sum('amount') ?? 0;
    $this->projects[$id]['vendor_bids_sum'] = (float) $bidsSum;
    $this->updateProjectBalance($id);
    }

    public function updateProjectBalance($project_id)
    {
        $id = (int) $project_id;
        if (!isset($this->projects[$id])) { return; }
        $amount = ($this->projects[$id]['amount'] ?? 0) > 0 ? $this->projects[$id]['amount'] : 0;
        $totalPaid = $this->projects[$id]['vendor_expenses_sum'];
        $bidsAmount = $this->projects[$id]['vendor_bids_sum'];
        $balance = round(($bidsAmount - $totalPaid) - $amount, 2);
        $this->projects[$id]['balance'] = $balance;
    }

    public function removeProject($project_id_to_remove)
    {
    $id = (int) $project_id_to_remove;
    if (!isset($this->projects[$id])) { return; }
    $this->projects[$id]['show'] = false;
    $this->projects[$id]['amount'] = null;
    $this->projects[$id]['disabled'] = false;

        $this->project_id = '';
    }

    public function getVendorCheckSumProperty()
    {
        $total = 0;
        $total += collect($this->projects)
            ->where('show', true)
            ->filter(fn($p) => ($p['amount'] ?? 0) > 0)
            ->sum('amount');

        // Selected vendor reimbursements reduce the payment
        $total -= $this->vendor_reimbursement_expenses
            ->filter(fn ($e) => $this->selectedVendorReimbursementExpenses[$e->id] ?? false)
            ->sum('amount');

        return $total;
    }

    /**
     * Reimbursement deductions can only settle through a check, so paying via
     * an employee (Paid By) is disabled while any reimbursement is selected —
     * same rule as timesheet payments.
     */
    public function getDisablePaidByProperty(): bool
    {
        return $this->vendor_reimbursement_expenses
            ->filter(fn ($e) => $this->selectedVendorReimbursementExpenses[$e->id] ?? false)
            ->isNotEmpty();
    }

    /**
     * Whether the payment is submittable — shared rules in
     * HandlesChecks::canSubmitCheckPayment().
     */
    public function getCanSubmitPaymentProperty(): bool
    {
        return $this->canSubmitCheckPayment((float) $this->getVendorCheckSumProperty());
    }

    /**
     * Validate the payment, then show the shared confirmation modal (round
     * hundreds skip straight to save — same behavior as timesheet payments).
     */
    public function confirmPayment()
    {
        $this->validate();

        if (fmod($this->getVendorCheckSumProperty(), 100) == 0) {
            return $this->save();
        }

        $this->modal('confirm-payment')->show();
    }

    public function save()
    {
        $this->validate();
        $check = $this->form->store();

        //09-06-2023 move somewhere else?
        //send email to vendor being paid...
        if (! is_null($check)) {
            // Check total = project expenses − attached vendor reimbursements
            // (centralized in Check::recalculateAmount)
            $check->recalculateAmount();

            //queue email job
            $auth_user = auth()->user();
            $vendor = $this->vendor;
            SendVendorPaymentEmailJob::dispatch($auth_user, $vendor, $check);

            return redirect()->route('checks.show', $check->id);
        } else {
            return redirect()->route('vendors.show', $this->vendor->id);
        }
    }

    #[Title('Vendor Payment')]
    public function render()
    {
        return view('livewire.vendors.payment-form');
    }
}

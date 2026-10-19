<?php

namespace App\Livewire\Timesheets;

// Removed unused BankAccount import (not referenced in this component)
use App\Models\Timesheet;
use App\Models\User;
use App\Models\Vendor;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

use App\Services\Timesheets\TimesheetPaymentOptions;
use App\Traits\HandlesChecks;

use Livewire\Component;
use Livewire\Attributes\Title;
use App\Livewire\Forms\TimesheetPaymentForm;

class TimesheetPaymentCreate extends Component
{
    use AuthorizesRequests, HandlesChecks;

    public TimesheetPaymentForm $form;

    public User $user;

    // Source collections (Eloquent collections) for display
    public $weekly_timesheets = [];
    public $employee_weekly_timesheets = [];
    public $user_paid_expenses = [];
    public $user_reimbursement_expenses = [];

    // Separate selection state arrays keyed by model id to avoid Livewire collection key loss
    public array $selectedWeeklyTimesheets = [];
    public array $selectedEmployeeWeeklyTimesheets = [];
    public array $selectedUserPaidExpenses = [];
    public array $selectedUserReimbursementExpenses = [];
    public $employees = [];
    public $view_text = [];
    // Resolved via vendor (if user is payable through an intermediary vendor)
    public ?Vendor $viaVendor = null;
    // Mirror of computed weekly total for validation purposes
    public float $payment_total = 0.0;
    // Display-only computed payee name (mirrors future Check::owner semantics)
    public string $payeeName = '';

    protected $listeners = ['refreshComponent' => '$refresh'];

    protected function rules(): array
    {
        // Re-introduce prefixed form rules so Livewire validates nested form.* fields
        // and merge with check-related rules from HandlesChecks trait.
        return array_merge(
            // prefix each form field with form.
            collect($this->form->rules())
                ->mapWithKeys(fn($rule, $key) => ["form.$key" => $rule])
                ->toArray(),
            $this->handlesChecksRules(),
            [
                'payment_total' => 'numeric|gt:0',
            ]
        );
    }

    protected function messages(): array
    {
        return array_merge($this->handlesChecksMessages(), [
            'payment_total.gt' => 'Payment needs to be greater than $0.00',
        ]);
    }

    public function mount()
    {
        $this->authorize('viewPayment', [Timesheet::class, $this->user]);
        $this->view_text = [
            'button_text' => 'Pay '.$this->user->first_name,
            'form_submit' => 'save',
        ];

        // NOTE: As of refactor (maintaining audit trail for employees paid via an intermediary vendor),
        // we ALWAYS attribute newly created timesheet payment checks to the employee's user_id.
        // Previous behavior stored vendor_id (= viaVendor) and left user_id null, which obscured
        // direct user relationships on checks  (e.g. timesheet detail views & policies relying on user_id).
        // The form logic now ignores viaVendor for the actual Check record; payee display still uses viaVendor.

        // Shared with the app's TimesheetPaymentController — see
        // TimesheetPaymentOptions::forUser().
        $options = app(TimesheetPaymentOptions::class)->forUser(auth()->user(), $this->user);

        $this->employees = $options['employees'];
        $this->viaVendor = $this->user->via_vendor;
        $this->payeeName = $options['payee_name'];

        $this->weekly_timesheets = $options['weekly_timesheets'];
        $this->selectedWeeklyTimesheets = $this->weekly_timesheets->pluck('id')->mapWithKeys(fn ($id) => [$id => true])->toArray();

        $this->employee_weekly_timesheets = $options['employee_weekly_timesheets'];
        $this->selectedEmployeeWeeklyTimesheets = $this->employee_weekly_timesheets->pluck('id')->mapWithKeys(fn ($id) => [$id => true])->toArray();

        $this->user_paid_expenses = $options['paid_expenses'];
        $this->selectedUserPaidExpenses = $this->user_paid_expenses->pluck('id')->mapWithKeys(fn ($id) => [$id => false])->toArray();

        $this->user_reimbursement_expenses = $options['reimbursement_expenses'];
        $this->selectedUserReimbursementExpenses = $this->user_reimbursement_expenses->pluck('id')->mapWithKeys(fn ($id) => [$id => false])->toArray();

        if ($this->weekly_timesheets->isEmpty()) { $this->weekly_timesheets = collect(); }
        if ($this->employee_weekly_timesheets->isEmpty()) { $this->employee_weekly_timesheets = collect(); }
        if ($this->user_paid_expenses->isEmpty()) { $this->user_paid_expenses = collect(); }
        if ($this->user_reimbursement_expenses->isEmpty()) { $this->user_reimbursement_expenses = collect(); }

        $this->form->setUser($this->user);
    }

    public function updated($field, $value)
    {
        // Keep validation errors visible but don't let the exception skip the
        // render — the check-image preview and the disabled state of the Pay
        // button must still update.
        try {
            // Minimal: removed transient checkbox mirroring; selection arrays drive UI & totals directly.
            $this->handleChecksUpdated($field, $value);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->setErrorBag($e->validator->errors());
        }
    }

    /**
     * Whether the payment is submittable — shared rules in
     * HandlesChecks::canSubmitCheckPayment().
     */
    public function getCanSubmitPaymentProperty(): bool
    {
        return $this->canSubmitCheckPayment((float) $this->getWeeklyTimesheetsTotalProperty());
    }

    public function getWeeklyTimesheetsTotalProperty()
    {
        $total = 0;
        $total += $this->weekly_timesheets->filter(fn ($t) => $this->selectedWeeklyTimesheets[$t->id] ?? false)->sum('amount');
        $total += $this->employee_weekly_timesheets->filter(fn ($t) => $this->selectedEmployeeWeeklyTimesheets[$t->id] ?? false)->sum('amount');
        $total += $this->user_paid_expenses->filter(fn ($e) => $this->selectedUserPaidExpenses[$e->id] ?? false)->sum('amount');
        $total -= $this->user_reimbursement_expenses->filter(fn ($e) => $this->selectedUserReimbursementExpenses[$e->id] ?? false)->sum('amount');
        return $total;
    }

    public function getDisablePaidByProperty()
    {
        return
            $this->user_paid_expenses->filter(fn ($e) => $this->selectedUserPaidExpenses[$e->id] ?? false)->isNotEmpty()
            || $this->user_reimbursement_expenses->filter(fn ($e) => $this->selectedUserReimbursementExpenses[$e->id] ?? false)->isNotEmpty();
    }

    /**
     * Validate the payment form, then show confirmation modal if amount is not a round hundred.
     */
    public function confirmPayment()
    {
        $this->authorize('viewAnyPayment', Timesheet::class);
        
        // Sync computed total into a concrete property for validation
        $this->payment_total = (float) $this->weekly_timesheets_total;
        
        $this->validate();
        
        // If amount is a round hundred, save directly without modal
        if (fmod($this->payment_total, 100) == 0) {
            return $this->save();
        }
        
        // Open confirmation modal
        $this->modal('confirm-payment')->show();
    }

    public function save()
    {
        $this->authorize('viewAnyPayment', Timesheet::class);
        // Sync computed total into a concrete property for validation
        $this->payment_total = (float) $this->weekly_timesheets_total;

        $this->validate();


        $check = $this->form->store();

        if (! $check) {
            return $this->redirect(route('timesheets.payments'), navigate: true);
        }

        return $this->redirect(route('checks.show', $check->id), navigate: true);
    }

    #[Title('Timesheets Payment')]
    public function render()
    {
        $this->authorize('viewPayment', [Timesheet::class, $this->user]);
        return view('livewire.timesheets.payment-form');
    }
}

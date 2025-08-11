<?php

namespace App\Livewire\Timesheets;

use App\Models\BankAccount;
use App\Models\Expense;
use App\Models\Timesheet;
use App\Models\User;
use App\Models\Vendor;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

use App\Traits\HandlesChecks;

use Livewire\Component;
use Livewire\Attributes\Title;
use App\Livewire\Forms\TimesheetPaymentForm;

class TimesheetPaymentCreate extends Component
{
    use AuthorizesRequests, HandlesChecks;

    public TimesheetPaymentForm $form;

    public User $user;

    public $weekly_timesheets = [];
    public $employee_weekly_timesheets = [];
    public $user_paid_expenses = [];
    public $user_reimbursement_expenses = [];
    public $employees = [];
    public $view_text = [];

    protected $listeners = ['refreshComponent' => '$refresh'];

    protected function rules(): array
    {
        return $this->componentMergedRules(
            $this->form->rules(),
            [
                'weekly_timesheets.*.checkbox'         => 'nullable',
                'employee_weekly_timesheets.*.checkbox'  => 'nullable',
                'user_paid_expenses.*.checkbox'          => 'nullable',
                'user_reimbursement_expenses.*.checkbox' => 'nullable',
                // 'user_paid_by_reimbursements.*.checkbox' => 'nullable',
                // You can also add other top-level rules here if needed.
            ]
        );
    }

    public function mount()
    {
        $this->authorize('viewPayment', [Timesheet::class, $this->user]);
        $this->view_text = [
            'card_title' => 'Create Daily Hours',
            'button_text' => 'Pay '.$this->user->first_name,
            'form_submit' => 'save',
        ];

        $this->employees = auth()->user()->vendor->users()->where('is_employed', 1)->whereNot('users.id', $this->user->id)->get();

        $this->user->pivot_user_vendor = $this->user->vendors()->where('vendors.id', auth()->user()->vendor->id)->first()->pivot->via_vendor_id;

        if (! is_null($this->user->pivot_user_vendor)) {
            $via_vendor_back = Vendor::withoutGlobalScopes()->findOrFail($this->user->pivot_user_vendor);
            $this->user->payee_name = $via_vendor_back->business_name;
            $this->user->via_vendor_back = auth()->user()->vendor;
        } else {
            $this->user->payee_name = $this->user->full_name;
            $this->user->via_vendor_back = $this->user->vendor;
        }

        $this->weekly_timesheets =
            Timesheet::where('user_id', $this->user->id)
                ->whereNull('check_id')
                ->whereNull('paid_by')
                ->whereNull('deleted_at')
                ->orderBy('date', 'DESC')
                ->get()
                ->each(function ($item, $key) {
                    $item->checkbox = true;
                })
                ->keyBy('id');

        $this->employee_weekly_timesheets =
                Timesheet::with('user')
                    ->where('paid_by', $this->user->id)
                    ->whereNull('check_id')
                    ->whereNull('deleted_at')
                    ->orderBy('date', 'DESC')
                    ->get()
                    ->each(function ($item, $key) {
                        $item->checkbox = true;
                    })
                    ->keyBy('id');

        $this->user_paid_expenses =
            Expense::where('paid_by', $this->user->id)
                ->where(function ($query) {
                    $query->whereNull('reimbursment')->orWhere('reimbursment', 'Client');
                })
                ->whereNull('check_id')
                ->orderBy('date', 'DESC')
                ->get()
                ->each(function ($item, $key) {
                    $item->checkbox = true;
                })
                ->keyBy('id');

        $this->user_reimbursement_expenses =
            Expense::where('reimbursment', $this->user->id)
                ->whereNull('paid_by')
                ->whereNull('check_id')
                ->orderBy('date', 'DESC')
                ->get()
                ->each(function ($item, $key) {
                    $item->checkbox = true;
                })
                ->keyBy('id');

        // $this->user_paid_by_reimbursements =
        //     Expense::where('paid_by', $this->user->id)
        //         ->whereNotIn('reimbursment', ['', 'Client'])
        //         ->whereNull('check_id')
        //         ->orderBy('date', 'DESC')
        //         ->get()
        //         ->each(function ($item, $key) {
        //             $item->checkbox = true;
        //             // $item->amount = -$item->amount;
        //         })
        //         ->keyBy('id');
        // dd($this->user_paid_by_reimbursements);


        if ($this->weekly_timesheets->isEmpty()) {
            $this->weekly_timesheets = collect();
        }

        if ($this->employee_weekly_timesheets->isEmpty()) {
            $this->employee_weekly_timesheets = collect();
        }

        if ($this->user_paid_expenses->isEmpty()) {
            $this->user_paid_expenses = collect();
        }

        if ($this->user_reimbursement_expenses->isEmpty()) {
            $this->user_reimbursement_expenses = collect();
        }

        // if ($this->user_paid_by_reimbursements->isEmpty()) {
        //     $this->user_paid_by_reimbursements = collect();
        // }

        $this->form->setUser($this->user);
    }

    public function updated($field, $value)
    {
        $this->handleChecksUpdated($field, $value);
    }

    public function getWeeklyTimesheetsTotalProperty()
    {
        $total = 0;

        //weekly_timesheets
        $total += $this->weekly_timesheets->where('checkbox', true)->sum('amount');

        //employee_weekly_timesheets
        $employee_weekly_timesheets_total = $this->employee_weekly_timesheets->where('checkbox', true)->sum('amount');
        $total += $employee_weekly_timesheets_total;

        //user_paid_expenses
        $user_paid_expenses_total = $this->user_paid_expenses->where('checkbox', true)->sum('amount');
        $total += $user_paid_expenses_total;

        //user_reimbursement_expenses
        $total -= $this->user_reimbursement_expenses->where('checkbox', true)->sum('amount');

        // //user_paid_by_reimbursements
        // $user_paid_by_reimbursements = $this->user_paid_by_reimbursements->where('checkbox', true)->sum('amount');
        // if ($user_paid_by_reimbursements != '0.00') {
        //     $confirm_disable[] = true;
        // }
        // dd($user_paid_by_reimbursements);
        // $total -= $user_paid_by_reimbursements;

        return $total;
    }

    public function getDisablePaidByProperty()
    {
        return
        $this->user_paid_expenses->where('checkbox', true)->isNotEmpty()
        || $this->user_reimbursement_expenses->where('checkbox', true)->isNotEmpty();
    }

    public function save()
    {
        $this->authorize('viewAnyPayment', Timesheet::class);

        //validate Pay User Total Check is greater than $0 / $this->weekly_timesheets has at least one Item in Collection
        if ($this->weekly_timesheets_total <= 0) {
            $this->addError('weekly_timesheets_total', 'Payment needs to be greater than $0.00');
        } else {
            $this->validate();
            $redirect_route = $this->form->store();

            if ($redirect_route == 'timesheets') {
                return redirect()->route('timesheets.payments');
            } else {
                $check = $redirect_route;
                // $expenses = $check->expenses;
                // foreach($expenses as $expense){
                //     if(is_numeric($expense->reimbursment)){
                //         $expense->amount = $expense->amount;
                //     }
                // }

                //$check->expenses->whereNotNull('paid_by')->whereNull('reimbursment')->sum('amount')
                // $check->amount = $check->timesheets->sum('amount') + $expenses->sum('amount');
                $check->amount = $check->amount;
                $check->save();

                return redirect()->route('checks.show', $check->id);
            }
        }
    }

    #[Title('Timesheets Payment')]
    public function render()
    {
        $this->authorize('viewPayment', [Timesheet::class, $this->user]);

        return view('livewire.timesheets.payment-form');
    }
}

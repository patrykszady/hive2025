<?php

namespace App\Livewire\Users;

use App\Models\Check;
use App\Models\Expense;
use App\Models\Timesheet;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Title;
use Livewire\Component;

class UserShow extends Component
{
    use AuthorizesRequests;

    public User $user;

    public $year = 2023;

    public $timesheets_paid = 0;

    public $timesheets_paid_by = 0;

    public $timesheets_paid_others = 0;

    public $expenses_paid = 0;

    public $distribution_checks = 0;

    public $checks_written = 0;

    public $distribution_expenses = 0;

    public $user_checks = 0;

    public $difference = 0;
    // public $modal_show = FALSE;

    // protected $listeners = ['showMember'];

    // public function showMember(User $user)
    // {
    //     // $this->modal_show = true;
    //     return view('livewire.users.show', [
    //         'user' => $user,
    //     ]);
    // }
    public function mount()
    {
        $this->user->this_vendor = $this->user->vendors->where('id', auth()->user()->vendor->id)->first();

        if (! is_null($this->user->this_vendor)) {
            $user_distribution = $this->user->distributions->first() ? $this->user->distributions->first()->id : null;
            $year = $this->year;

            $this->checks_written =
                Check::where('user_id', $this->user->id)
                    ->whereYear('date', $this->year)
                    ->where('belongs_to_vendor_id', $this->user->this_vendor->id)
                    // ->pluck('id');
                    // ->get();
                    ->sum('amount');

            //Member Extra Payments
            // if doesnt have a distribution
            if (! $user_distribution) {
                $this->user_checks =
                    Check::where('user_id', $this->user->id)
                        ->whereYear('date', $this->year)
                        ->whereDoesntHave('timesheets')
                        ->where('belongs_to_vendor_id', $this->user->this_vendor->id)
                        ->sum('amount');
            }

            //where check->date is $this->year
            $this->timesheets_paid =
                Timesheet::where('user_id', $this->user->id)
                    ->where('vendor_id', $this->user->this_vendor->id)
                    ->whereNull('paid_by')
                    ->whereHas('check', function ($query) use ($year) {
                        return $query->whereYear('date', $year);
                    })
                    // ->get();
                    ->sum('amount');

            // dd($this->timesheets_paid);
            if ($user_distribution) {
                $this->distribution_checks =
                    Expense::where('distribution_id', $user_distribution)
                        // ->whereNotNull('check_id')
                        // ->whereYear('date', $this->year)
                        ->whereHas('check', function ($query) use ($year) {
                            return $query->whereYear('date', $year);
                        })
                        ->sum('amount');
            } else {
                $this->distribution_checks = 0.00;
            }

            $this->timesheets_paid_others =
                Timesheet::whereNot('user_id', $this->user->id)
                    ->where('paid_by', $this->user->id)
                    ->where('vendor_id', $this->user->this_vendor->id)
                    // ->whereNotNull('check_id')
                    // ->whereYear('date', $this->year)
                    ->whereHas('check', function ($query) use ($year) {
                        return $query->whereYear('date', $year);
                    })
                    ->sum('amount');

            $this->expenses_paid =
                Expense::where('paid_by', $this->user->id)
                    // ->whereYear('date', $year)
                    // ->whereNotNull('check_id')
                    ->whereHas('check', function ($query) use ($year) {
                        return $query->whereYear('date', $year);
                    })
                    // ->get();
                    ->sum('amount');
            // dd($this->expenses_paid);
            // when(!is_null($user_distribution), function ($query) use ($user_distribution) {
            //     $query->where('distribution_id', $user_distribution);
            // })

            $this->timesheets_paid_by =
                Timesheet::withoutGlobalScopes()
                    ->where('user_id', $this->user->id)
                    ->where('vendor_id', $this->user->this_vendor->id)
                    ->whereNotNull('paid_by')
                    ->whereHas('check', function ($query) use ($year) {
                        return $query->withoutGlobalScopes()->whereYear('date', $year);
                    })
                    ->sum('amount');
            //         ->get();
            // dd($this->timesheets_paid_by);

            if ($user_distribution) {
                $this->distribution_expenses =
                Expense::where('distribution_id', $user_distribution)
                    ->whereNull('check_id')
                    ->whereYear('date', $year)
                    // whereHas('transactions') ...transaction_date = $year
                    // ->get();
                    ->sum('amount');
            } else {
                $this->distribution_expenses = 0.00;
            }

            // dd($this->checks_written - ($this->timesheets_paid + $this->distribution_checks) - ($this->timesheets_paid_others + $this->expenses_paid));
            $this->difference = round($this->checks_written - $this->timesheets_paid - $this->distribution_checks - $this->user_checks - $this->timesheets_paid_others - $this->expenses_paid, 2);
        }
    }

    #[Title('User')]
    public function render()
    {
        return view('livewire.users.show');
    }
}

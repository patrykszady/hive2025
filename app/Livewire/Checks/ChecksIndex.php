<?php

namespace App\Livewire\Checks;

use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Check;
use App\Models\Vendor;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

// #[Lazy]
class ChecksIndex extends Component
{
    use AuthorizesRequests, WithPagination;

    /**
     * How many skeleton rows the loading placeholder should paint — the card's
     * page size, so the skeleton is the same height as the table that replaces
     * it (no jump on load). Callers that can cheaply COUNT the real rows pass
     * the smaller of the two.
     */
    public static function placeholderRows(?string $view = null): int
    {
        return $view === null ? 10 : 5;
    }

    /**
     * Column defs for the checks table — the loading skeleton renders from the
     * same array as the real header row, so widths can never drift apart.
     *
     * @return array<int, array{label: string, width: string, skeleton?: string, skeletonWidth?: string}>
     */
    public static function columnDefs(): array
    {
        return [
            ['label' => 'Amount', 'width' => 'w-[14%]', 'skeletonWidth' => 'w-16'],
            ['label' => 'Date', 'width' => 'w-[13%]', 'skeletonWidth' => 'w-16'],
            ['label' => 'Check #', 'width' => 'w-[13%]', 'skeletonWidth' => 'w-12'],
            ['label' => 'Bank', 'width' => 'w-[18%] min-w-0', 'skeletonWidth' => 'w-24'],
            ['label' => 'Payee', 'width' => 'w-[24%] min-w-0', 'skeletonWidth' => 'w-32'],
            ['label' => 'Status', 'width' => 'w-[18%] min-w-0', 'skeleton' => 'badge'],
        ];
    }

    public $banks = [];

    public $vendors = [];

    #[Url(except: '')]
    public $bank = '';

    #[Url(except: '')]
    public $check_number = '';

    #[Url(except: '')]
    public $amount = '';

    #[Url(except: '')]
    public $check_type = '';

    #[Url(except: '')]
    public $vendor = '';

    public $view = null;

    public $expense_check_id = '';

    public $check_ids = [];

    public $sortBy = 'date';

    public $sortDirection = 'desc';

    public function updating($field)
    {
        $this->resetPage();
    }

    public function mount()
    {
        //where $check->transactions dont equal $check->amount
        // $checks = Check::whereBetween('date', ['2022-09-01', '2023-09-01'])->whereDoesntHave('transactions')->where('check_type', '!=', 'Cash')->get();

        $this->vendors = Vendor::orderBy('business_name')->get();
        $this->banks =
            Bank::orderBy('created_at', 'DESC')
                ->with('accounts')
                ->whereHas('accounts', function ($query) {
                    return $query->whereIn('type', ['Checking', 'Savings']);
                })->get();
        //->groupBy('plaid_ins_id')
        // $this->banks =
        // Bank::with('accounts')
        //     ->whereHas('accounts', function ($query) {
        //         return $query->whereIn('type', ['Checking', 'Savings']);
        //     })->get()->groupBy('plaid_ins_id');
    }

    #[Computed]
    public function checks()
    {
        if ($this->view == null) {
            $paginate_number = 10;
        } else {
            $paginate_number = 5;
        }

        if ($this->bank) {
            $bank_plaid_ins_id = Bank::findOrFail($this->bank)->plaid_ins_id;
            $bank_ids = Bank::where('plaid_ins_id', $bank_plaid_ins_id)->pluck('id')->toArray();

            $bank_accounts = BankAccount::whereIn('bank_id', $bank_ids)->pluck('id')->toArray();
        } else {
            $bank_accounts = BankAccount::all()->pluck('id')->toArray();
        }

        $check_number = $this->check_number;
        $amount = $this->amount;
        $checks =
            Check::orderBy('date', 'DESC')
                //distributions
                ->with(['expenses', 'bank_account', 'transactions'])
                ->where(function ($query) use ($bank_accounts) {
                    $query->whereIn('bank_account_id', $bank_accounts)
                        ->orWhereNull('bank_account_id');
                })
                ->where('check_type', 'like', "%{$this->check_type}%")
                ->when($check_number, function ($query) {
                    return $query->where(function ($q) {
                        $q->where('check_number', 'like', "%{$this->check_number}%")
                            ->orWhere('check_type', 'like', "%{$this->check_number}%");
                    });
                })
                ->when($this->expense_check_id, function ($query) {
                    return $query->where('id', $this->expense_check_id);
                })
                ->when(!empty($this->check_ids), function ($query) {
                    return $query->whereIn('id', $this->check_ids);
                })
                ->when($amount, function ($query) {
                    return $query->where('amount', 'like', "{$this->amount}%");
                })
                ->when($this->vendor, function ($query) {
                    return $query->where('vendor_id', $this->vendor);
                })
                ->paginate($paginate_number);

        return $checks;
    }

    #[Title('Checks')]
    public function render()
    {
        $this->authorize('viewAny', Check::class);
        return view('livewire.checks.index', [
        ]);
    }
}

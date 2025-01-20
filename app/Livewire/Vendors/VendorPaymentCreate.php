<?php

namespace App\Livewire\Vendors;

use App\Jobs\SendVendorPaymentEmailJob;
use App\Livewire\Forms\VendorPaymentForm;
use App\Models\BankAccount;
use App\Models\Check;
use App\Models\Project;
use App\Models\Vendor;
use Carbon\Carbon;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

class VendorPaymentCreate extends Component
{
    use AuthorizesRequests;

    public VendorPaymentForm $form;

    public Vendor $vendor;

    public $next_check_auto = false;

    public $project_id = '';

    public $projects = [];

    public $employees = [];

    public $bank_accounts = [];

    public $payment_projects = [];

    public $saved_expenses = [];

    public $disable_paid_by = false;

    public $view_text = [
        'card_title' => 'Create Vendor Payments',
        'button_text' => 'Create Vendor Check',
        'form_submit' => 'save',
    ];

    protected $listeners = ['updateProjectBids'];

    protected function rules()
    {
        return [
            'project_id' => 'required',
            'projects.*.show' => 'nullable',
            'projects.*.vendor_expenses_sum' => 'nullable',
            'projects.*.vendor_bids_sum' => 'nullable',
            'projects.*.balance' => 'nullable',
            'projects.*.amount' => 'required|numeric|min:0.01|regex:/^-?\d+(\.\d{1,2})?$/',
        ];
    }

    public function mount()
    {
        //09-05-2023 if proejct not active ...add in dropdown
        // $projects = Project::active()->orderBy('created_at', 'DESC')->get();
        //whereNotIn('id', $existing_projects)

        $this->projects =
            Project::where('created_at', '>', Carbon::now()->subYears(2)->format('Y-m-d'))
                ->status(['Active', 'Complete', 'Service Call', 'Service Call Complete'])
                ->sortBy([['last_status.title', 'asc'], ['last_status.start_date', 'desc']])
                // ->with(['expenses' => function ($query) {
                //     return $query->where('vendor_id', '4');
                //     }])
                // ->get()
                ->each(function ($item, $key) {
                    $item->show = false;
                    $item->name = $item->name;
                    $item->disabled = false;
                })
                ->keyBy('id');

        $this->form->date = today()->format('Y-m-d');
        $this->employees = auth()->user()->vendor->users()->where('is_employed', 1)->get();
        $this->bank_accounts = BankAccount::with('bank')->where('type', 'Checking')
            ->whereHas('bank', function ($query) {
                return $query->whereNotNull('plaid_access_token');
            })->get();
    }

    public function updated($field, $value)
    {
        // dd($field, $value);
        if ($field == 'form.bank_account_id') {
            $this->form->check_type = null;
            $this->form->check_number = null;
            $this->next_check_auto = false;
            $this->resetValidation('form.check_number');
        }

        if ($field == 'form.check_type') {
            if ($value == 'Check') {
                $next_check_number = Check::where('bank_account_id', $this->form->bank_account_id)->where('check_type', 'Check')->orderBy('date', 'DESC')->orderBy('created_at', 'DESC')->first()->check_number + 1;
                $this->form->check_number = $next_check_number;
                $this->next_check_auto = true;
            } else {
                $this->form->check_number = null;
                $this->next_check_auto = false;
                $this->resetValidation('form.check_number');
            }
        }

        if ($field == 'form.check_number') {
            $this->next_check_auto = false;
            $this->validateOnly($field);
        }

        if (substr($field, 0, 8) == 'projects') {
            $project_id = preg_replace('/[^0-9]/', '', $field);
            $this->updateProjectBalance($project_id);
        }

        $this->validateOnly($field);

        if (in_array($field, ['form.bank_account_id', 'form.paid_by'])) {
            $this->validateOnly('form.bank_account_id');
            $this->validateOnly('form.paid_by');
        }
    }

    // #[Computed]
    // public function projects()
    // {
    //     $vendors = Vendor::orderBy('business_name')->get(['id', 'business_name']);

    //     return $vendors;
    // }

    public function addProject()
    {
        $this->validateOnly('project_id');

        $project = $this->projects[$this->project_id];
        $project->show = true;
        $project->disabled = true;
        $project->vendor_expenses_sum = $project->expenses()->where('vendor_id', $this->vendor->id)->sum('amount');
        $project->vendor_bids_sum = $project->bids()->vendorBids($this->vendor->id)->sum('amount');
        $project->balance = $project->vendor_bids_sum - $project->vendor_expenses_sum;

        // $this->projects->reload();
        $this->project_id = '';
    }

    public function updateProjectBids($project_id)
    {
        $project = $this->projects[$project_id];
        $project['vendor_bids_sum'] = Project::findOrFail($project_id)->bids()->vendorBids($this->vendor->id)->sum('amount');

        $this->updateProjectBalance($project_id);
    }

    public function updateProjectBalance($project_id)
    {
        if ($this->projects[$project_id]->amount == null || $this->projects[$project_id]->amount <= 0) {
            $amount = 0;
        } else {
            $amount = $this->projects[$project_id]->amount;
        }

        $total_paid = $this->projects[$project_id]->vendor_expenses_sum;
        $bids_amount = $this->projects[$project_id]->vendor_bids_sum;
        $balance = ($bids_amount - $total_paid) - $amount;

        $this->projects[$project_id]->balance = $balance;
    }

    public function removeProject($project_id_to_remove)
    {
        $project = $this->projects[$project_id_to_remove];
        $project->show = false;
        $project->amount = null;

        $this->project_id = '';
    }

    public function getVendorCheckSumProperty()
    {
        $total = 0;
        $total += $this->projects->where('show', true)->where('amount', '>', 0)->sum('amount');

        return $total;
    }

    public function save()
    {
        //validate check total is greater than $0
        //if less than or equal to 0... send back with error
        if ($this->getVendorCheckSumProperty() <= 0) {
            return $this->addError('check_total_min', 'Check total needs to be greater than $0 and include at least 1 project.');
        } else {
            $check = $this->form->store();
        }

        //09-06-2023 move somewhere else?
        //send email to vendor being paid...
        if (! is_null($check)) {
            //get check total AMOUNT
            // + $check->timesheets->sum('amount')
            $check->amount = $check->expenses->sum('amount');
            $check->save();

            //queue
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

<?php

namespace App\Livewire\Vendors;

use App\Jobs\SendVendorPaymentEmailJob;
use App\Livewire\Forms\VendorPaymentForm;
use App\Traits\HandlesChecks;

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
    use AuthorizesRequests, HandlesChecks;

    public VendorPaymentForm $form;

    public Vendor $vendor;

    public $project_id = '';

    public $projects = [];

    public $employees = [];

    public $payment_projects_count = 0;

    public $saved_expenses = [];

    public $view_text = [
        'card_title' => 'Create Vendor Payments',
        'button_text' => 'Create Vendor Check',
        'form_submit' => 'save',
    ];

    protected $listeners = ['updateProjectBids'];

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
            ]
        );
    }

    public function mount()
    {
        //09-05-2023 if proejct not active ...add in dropdown
        $this->projects = Project::where('created_at', '>', Carbon::now()->subYears(2)->format('Y-m-d'))
            ->status(['Active', 'Complete', 'Service Call', 'Service Call Complete']) // Using your scope
            ->with('latestStatus') // Eager load the latest status for efficiency
            ->get() // Fetch projects into a collection
            ->sortBy([
                ['latestStatus.title', 'asc'], // Sort by title (ascending)
                ['latestStatus.start_date', 'desc'], // Then by start_date (descending)
            ])
            ->each(function ($item) {
                $item->show = false;
                $item->name = $item->name;
                $item->disabled = false;
                $item->order = 0;
            })
            ->keyBy('id');

        $this->form->date = today()->format('Y-m-d');
        $this->employees = auth()->user()->vendor->users()->where('is_employed', 1)->get();
    }

    public function updated($field, $value)
    {
        $this->handleChecksUpdated($field, $value);
    }

    public function addProject()
    {
        $this->validateOnly('project_id');

        $project = $this->projects[$this->project_id];
        $project->show = true;
        $project->disabled = true;
        $project->order = $this->payment_projects_count++;
        $project->vendor_expenses_sum = $project->expenses()->where('vendor_id', $this->vendor->id)->sum('amount');
        $project->vendor_bids_sum = $project->bids()->vendorBids($this->vendor->id)->sum('amount');
        $project->balance = $project->vendor_bids_sum - $project->vendor_expenses_sum;

        // dd($this->projects);
        // $this->projects->reload();
        $this->project_id = '';
    }

    public function updateProjectBids($project_id)
    {
        $project = $this->projects[$project_id];
        $project['vendor_bids_sum'] = $project->bids()->vendorBids($this->vendor->id)->sum('amount');

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
        $balance = round (($bids_amount - $total_paid) - $amount, 2);

        $this->projects[$project_id]->balance = $balance;
    }

    public function removeProject($project_id_to_remove)
    {
        $project = $this->projects[$project_id_to_remove];
        $project->show = false;
        $project->amount = null;
        $project->disabled = false;

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
            $this->validate();
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

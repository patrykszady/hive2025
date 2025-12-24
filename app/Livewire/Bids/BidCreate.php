<?php

namespace App\Livewire\Bids;

use App\Livewire\Forms\BidForm;
use App\Models\Bid;
use App\Models\Project;
use App\Models\Vendor;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
// use Livewire\Attributes\Computed;
// use Livewire\Attributes\Lazy;

use Livewire\Component;

class BidCreate extends Component
{
    use AuthorizesRequests;

    public BidForm $form;

    public $bids = [];

    public $project;

    public $vendor;

    public $context = 'project'; // 'project' or 'payment'

    public $view_text = [
        'card_title' => 'Create Bid',
        'button_text' => 'Save Bids',
        'form_submit' => 'save',
    ];

    protected $listeners = ['addBids', 'addChangeOrder', 'removeChangeOrder'];

    public function rules()
    {
        return [
            'bids.*.amount' => 'required|numeric|regex:/^-?\d+(\.\d{1,2})?$/',
            'bids.*.type' => 'required|numeric',
            'bids.*.project_id' => 'required|numeric',
            'bids.*.vendor_id' => 'required|numeric',
        ];
    }

    public function updated($field)
    {
        $this->validateOnly($field);
    }

    public function addBids(Vendor $vendor, Project $project, $context = 'project')
    {
        $this->vendor = $vendor;
        $this->project = $project;
        $this->context = $context;

        $this->bids =
            $this->project->bids()
                ->vendorBids($this->vendor->id)
                ->with('estimate_sections')
                ->orderBy('type')
                ->get()
                ->each(function ($item, $key) {
                    if ($item->amount == 0.00) {
                        $item->amount = null;
                    }
                    
                    // In payment context, never disable bids (we need to edit vendor payment amounts)
                    if ($this->context === 'payment') {
                        $item->has_estimate_sections = false;
                    } else {
                        // In project context, only disable if this bid belongs to the authenticated user's vendor 
                        // AND has estimate sections that reference it
                        $belongsToAuthVendor = $item->vendor_id == auth()->user()->vendor->id;
                        $hasEstimateSections = $item->estimate_sections->where('bid_id', $item->id)->isNotEmpty();
                        
                        $item->has_estimate_sections = $belongsToAuthVendor && $hasEstimateSections;
                    }
                    
                    // Display names consistently even if legacy records have off-by-one types.
                    $item->name = $key === 0 ? 'Original Bid' : 'Change Order '.$key;
                })
                ->toArray();

        if (empty($this->bids)) {
            $bid = [
                'amount' => null,
                'type' => 1,
                'project_id' => $this->project->id,
                'vendor_id' => $this->vendor->id,
                'name' => 'Original Bid',
                'has_estimate_sections' => false,
            ];

            $this->bids[] = $bid;
        }

        $this->modal('bids_form_modal')->show();
    }

    public function addChangeOrder()
    {
        $nextType = (int) (collect($this->bids)->max('type') ?? 0) + 1;

        $bid = [
            'amount' => null,
            'type' => $nextType,
            'project_id' => $this->project->id,
            'vendor_id' => $this->vendor->id,
            'name' => 'Change Order '.max(1, $nextType - 1),
            // In payment context, always allow editing of new change orders
            'has_estimate_sections' => $this->context === 'payment' ? false : false,
        ];
        $this->bids[] = $bid;
    }

    public function removeChangeOrder($index)
    {
        $bid = $this->bids[$index];
        if (isset($bid['id'])) {
            $bid = Bid::withoutGlobalScopes()->findOrFail($bid['id']);
            $bid->delete();
        }

        unset($this->bids[$index]);
        // $this->bids->forget($index);
    }

    public function save()
    {
        $this->form->store();

        // $route_name = app('router')->getRoutes()->match(app('request')->create(url()->previous()))->getName();
        // //09-01-2023 should be just one component $refresh..
        // //depends on route coming from... either VendorsPayment or ProjectsShow
        // //01-09-2023 why above? can we do this via session() ? why need to refreshComponent?

        // if($route_name == 'vendors.payment'){
        //     $this->dispatch('updateProjectBids', $this->project->id);
        // }elseif($route_name == 'projects.show'){
        //     $this->dispatch('refreshComponent')->to('projects.projects-show');
        // }else{
        //     dd('in else');
        //     abort(404);
        // }

        $this->dispatch('updateProjectBids', $this->project->id)->to('vendors.vendor-payment-create');
        // $this->dispatch('refreshComponent')->to('projects.project-show');

        // $this->dispatch('notify',
        //     type: 'success',
        //     content: 'Bids Updated'
        // );

        $this->modal('bids_form_modal')->close();
    }

    public function render()
    {
        return view('livewire.bids.form');
    }
}

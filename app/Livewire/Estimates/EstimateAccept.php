<?php

namespace App\Livewire\Estimates;

use App\Models\Bid;
use App\Models\Estimate;
use App\Models\Project;
use Livewire\Component;

class EstimateAccept extends Component
{
    public Estimate $estimate;

    public Project $project;

    public $sections = [];

    public $bids = [];

    public $payments = [];

    public $payments_outstanding = 0;

    public $start_date = null;

    public $end_date = null;

    protected $listeners = ['accept', 'addPayment', 'refreshComponent' => 'refreshEstimateData'];

    protected function rules()
    {
        return [
            'sections.*.bid_index' => 'nullable',
            'payments.*.description' => 'required|min:3',
            'payments.*.amount' => 'nullable',
            'start_date' => 'nullable',
            'end_date' => 'nullable',
        ];
    }

    public function mount(Estimate $estimate)
    {
        $this->estimate = $estimate;
        $this->project = $estimate->project;

        $this->bids = $this->project->bids()->vendorBids($this->estimate->vendor->id)->with('estimate_sections')->orderBy('type')->get();

        if ($this->bids->isEmpty()) {
            $bid = Bid::create([
                'amount' => 0.00,
                'type' => 1,
                'project_id' => $this->project->id,
                'vendor_id' => auth()->user()->vendor->id,
            ]);

            $this->bids->push($bid);
        }

        $bids = $this->bids;

        if (isset($this->estimate->options['start_date'])) {
            $this->start_date = $estimate->options['start_date'];
        }

        if (isset($this->estimate->options['end_date'])) {
            $this->end_date = $estimate->options['end_date'];
        }

        $sections = $this->estimate
            ->estimate_sections()
            ->with('bid')
            ->get();
        
        $this->sections = $sections->map(function ($item, $key) use ($bids) {
            $sectionArray = $item->toArray();
            
            if ($item->bid) {
                $bid_index = $bids->search(function ($bid) use ($item) {
                    return $item->bid->id === $bid->id;
                });
                // If search returns false (not found), default to 0
                $sectionArray['bid_index'] = $bid_index !== false ? $bid_index : 0;
            } else {
                // Default to the first bid (Original Bid) if no bid is associated
                $sectionArray['bid_index'] = 0;
            }
            
            return (object) $sectionArray;
        });
        if ($this->estimate->payments) {
            $this->payments = collect($this->estimate->payments);
        } else {
            $this->payments = [
                0 => [
                    'amount' => null,
                    'description' => null,
                ],
            ];

            $this->payments = collect($this->payments);
        }
    }

    public function accept()
    {
        $this->modal('accept_estimate_modal')->show();
    }

    public function refreshEstimateData()
    {
        // Refresh the estimate model to get fresh data
        $this->estimate = $this->estimate->fresh();
        $this->project = $this->estimate->project;

        // Reload bids to include any newly created change orders
        $this->bids = $this->project->bids()->vendorBids($this->estimate->vendor->id)->with('estimate_sections')->orderBy('type')->get();

        if ($this->bids->isEmpty()) {
            $bid = Bid::create([
                'amount' => 0.00,
                'type' => 1,
                'project_id' => $this->project->id,
                'vendor_id' => auth()->user()->vendor->id,
            ]);

            $this->bids->push($bid);
        }

        // Reload sections with fresh data
        $sections = $this->estimate
            ->estimate_sections()
            ->with('bid')
            ->get();
        
        $this->sections = $sections->map(function ($item, $key) {
            $sectionArray = $item->toArray();
            
            if ($item->bid) {
                $bid_index = $this->bids->search(function ($bid) use ($item) {
                    return $item->bid->id === $bid->id;
                });
                // If search returns false (not found), default to 0
                $sectionArray['bid_index'] = $bid_index !== false ? $bid_index : 0;
            } else {
                // Default to the first bid (Original Bid) if no bid is associated
                $sectionArray['bid_index'] = 0;
            }
            
            return (object) $sectionArray;
        });
    }

    //new estiamte Bid
    public function newEstimateBid($section_index)
    {
        $bid_index = $this->bids->max('type');
        $bid = Bid::create([
            'amount' => 0.00,
            'type' => $bid_index + 1,
            'project_id' => $this->project->id,
            'vendor_id' => auth()->user()->vendor->id,
        ]);
        $this->bids->push($bid);
        $this->sections[$section_index]->bid_index = $bid_index;
    }

    public function getPaymentsRemainingProperty()
    {
        $sections_total = $this->sections->where('bid_index', 0)->sum('total');
        $payments_sum = $this->payments->where('amount', '!=', '')->sum('amount');
        $this->payments_outstanding = round($sections_total - $payments_sum, 2);

        return $this->payments_outstanding;
    }

    //new Payment split
    public function addPayment()
    {
        $payment = [
            'amount' => null,
            'description' => null,
        ];

        $this->payments->push($payment);
        $this->payments = $this->payments->values();
    }

    public function removePayment($index)
    {
        $this->payments->forget($index);
        $this->payments = $this->payments->values();
    }

    public function save()
    {
        if ($this->payments_outstanding < 0) {
            $this->addError('payments_remaining_error', 'Amount Remaining cannot be less than $0.00');
        } else {
            $estimate = $this->estimate;
            $estimate_options = $this->estimate->options;

            if ($this->payments->where('amount', '!=', '')->sum('amount') != 0) {
                $estimate_options['payments'] = $this->payments->toArray();
            }

            $estimate_options['start_date'] = $this->start_date;
            $estimate_options['end_date'] = $this->end_date;

            $estimate->options = $estimate_options;
            $estimate->save();

            foreach ($this->bids as $bid_index => $bid) {
                $bid_sections = $this->sections->where('bid_index', $bid_index);

                if ($bid_sections->isEmpty()) {
                    // If no sections assigned to this bid, delete it (except for the first bid)
                    if ($bid_index != 0) {
                        $bid->delete();
                    } else {
                        // Keep the original bid but set amount to 0
                        $bid->amount = 0.00;
                        $bid->save();
                    }
                } else {
                    // Update bid amount and associate sections
                    $bid_amount = $bid_sections->sum('total');
                    $bid->amount = $bid_amount;
                    $bid->save();

                    foreach ($bid_sections as $section) {
                        // Find the actual EstimateSection model and update it
                        $sectionModel = $this->estimate->estimate_sections()->find($section->id);
                        if ($sectionModel) {
                            $sectionModel->bid_id = $bid->id;
                            $sectionModel->save();
                        }

                        // Keep the bid_index for UI purposes
                        $section->bid_index = $bid_index;
                    }
                }
            }

            $this->modal('accept_estimate_modal')->close();
            $this->dispatch('refreshComponent')->to('estimates.estimate-show');
            $this->dispatch('refresh')->to('projects.project-finances');

            $this->dispatch('notify',
                type: 'success',
                content: 'Estimate Finalized'
            );
        }
    }

    public function render()
    {
        return view('livewire.estimates.accept');
    }
}

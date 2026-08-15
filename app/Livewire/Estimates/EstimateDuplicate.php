<?php

namespace App\Livewire\Estimates;

use App\Models\Client;
use App\Models\Estimate;
use App\Models\EstimateSection;
use App\Models\EstimateLineItem;

use Flux;

use App\Livewire\Projects\ProjectShow;

use Livewire\Component;
use Livewire\Attributes\Computed;

use Illuminate\Support\Facades\Log;

class EstimateDuplicate extends Component
{
    public Estimate $estimate;
    public EstimateSection $section;

    public $client_projects = [];
    public $estimates = [];
    public $client_id = null;
    public $estimate_id = null;
    public $project_id = null;
    public $section_id = null;
    public $cost_mode = 'exact';
    public $include_allowances = true;

    public $view_text = [
        'card_title' => 'Duplicate This Estimate',
        'button_text' => 'Duplicate',
        'form_submit' => 'save',
    ];

    protected $listeners = ['duplicateModal', 'duplicateToEstimateModal'];

    protected function rules()
    {
        $rules = [
            'client_id' => 'required',
            'project_id' => 'required',
        ];

        // Add estimate_id validation when duplicating sections
        if ($this->view_text['form_submit'] === 'save_section') {
            $rules['estimate_id'] = 'required';
        }

        return $rules;
    }

    public function updated($field, $value)
    {
        if ($field == 'client_id') {
            if ($value) {
                $this->project_id = null;
                $client = $this->clients->where('id', $value)->first();
                $this->client_projects = $client->projects;
            } else {
                $this->resetValidation();
            }
        }

        $this->validateOnly($field);
    }

    public function duplicateModal(Estimate $estimate)
    {
        $this->estimate = $estimate;
        $this->modal('estimate_duplicate_modal')->show();
    }

    public function duplicateToEstimateModal(EstimateSection $section)
    {
        $this->section = $section;
        $this->section_id = $section->id;

        // Get estimates from the same project but exclude the current estimate
        // Use the query builder instead of the collection
        $this->estimates = Estimate::where('project_id', $this->section->estimate->project_id)
            ->where('id', '!=', $section->estimate_id)
            ->orderBy('created_at', 'DESC')
            ->get();

        $this->view_text = [
            'card_title' => 'Duplicate This Section to Estimate',
            'button_text' => 'Duplicate',
            'form_submit' => 'save_section',
        ];

        $this->modal('estimate_duplicate_modal')->show();
    }

    #[Computed]
    public function clients()
    {
        return Client::cachedDropdownList();
    }

    #[Computed]
    public function project_estimates()
    {
        if (!$this->project_id) {
            return collect();
        }

        return Estimate::where('project_id', $this->project_id)
            ->withCount('estimate_sections')
            ->get();
    }

    public function save_section()
    {
        $this->validate();

        if ($this->estimate_id === 'new') {
            $new_estimate = Estimate::create([
                'project_id' => $this->project_id,
                'belongs_to_vendor_id' => auth()->user()->vendor->id,
            ]);
        } else {
            // Prevent duplicating to the same estimate
            if ($this->estimate_id == $this->section->estimate_id) {
                throw new \Exception('Cannot duplicate section to the same estimate. Please select a different estimate.');
            }

            $new_estimate = Estimate::findOrFail($this->estimate_id);
        }

        // Create new section by replicating the original
        $new_section = $this->section->replicate();
        $new_section->estimate_id = $new_estimate->id;
        $new_section->bid_id = null;
        $new_section->name = $this->section->name . ' - Copy from Estimate ' . $this->section->estimate_id;

        // IMPORTANT: Reset total to 0 so the observer can calculate it correctly
        $new_section->total = 0;

        $saved = $new_section->save();

        if (!$saved) {
            throw new \Exception('Failed to save section');
        }

        // Duplicate all line items for this section
        foreach ($this->section->estimate_line_items as $estimate_line_item) {
            // Create a new EstimateLineItem instance instead of replicating
            $new_line_item = new EstimateLineItem();

            // Set the foreign keys for the new estimate and section
            $new_line_item->estimate_id = $new_estimate->id;
            $new_line_item->section_id = $new_section->id;

            // Copy estimate-specific data from the original estimate_line_item
            $new_line_item->quantity = $estimate_line_item->quantity;
            // Don't set order - let Sortable trait handle it

            // Use the related line_item template data
            $line_item_template = $estimate_line_item->line_item;

            // Use template data for these fields
            $new_line_item->line_item_id = $line_item_template->id;
            $new_line_item->name = $estimate_line_item->name ?? $line_item_template->name;
            $new_line_item->category = $estimate_line_item->category ?? $line_item_template->category;
            $new_line_item->sub_category = $estimate_line_item->sub_category ?? $line_item_template->sub_category;
            $new_line_item->unit_type = $estimate_line_item->unit_type ?? $line_item_template->unit_type;
            $new_line_item->desc = $estimate_line_item->desc ?? $line_item_template->desc;
            $new_line_item->notes = $estimate_line_item->notes ?? $line_item_template->notes;

            if ($this->cost_mode === 'exact') {
                $new_line_item->cost = $estimate_line_item->cost;
                $new_line_item->total = $estimate_line_item->cost * $estimate_line_item->quantity;
            } else {
                $new_line_item->cost = $line_item_template->cost;
                $new_line_item->total = $line_item_template->cost * $estimate_line_item->quantity;
            }

            $new_line_item->save(); // Observer will add this to section total

            // Duplicate allowances
            if ($this->include_allowances) {
                foreach ($estimate_line_item->allowances as $allowance) {
                    $new_line_item->allowances()->create([
                        'description' => $allowance->description,
                        'amount' => $allowance->amount,
                    ]);
                }
            }
        }

        // Reset form
        $this->estimate_id = null;

        $this->modal('estimate_duplicate_modal')->close();

        $this->dispatch('toast-show',
            duration: 5000,
            slots: [
                'heading' => 'Section Duplicated Successfully',
                'text' => 'Section "' . $new_section->name . '" has been added to Estimate #' . $new_estimate->id,
                'action' => 'Go to estimate',
            ],
            dataset: [
                'variant' => 'success',
                'position' => 'top end',
                'route' => route('estimates.show', $new_estimate->id),
            ],
        );
    }

    public function save()
    {
        $this->validate();

        //get current estimate and duplicate sections and line_items
        $new_estimate = Estimate::create([
            'project_id' => $this->project_id,
            'belongs_to_vendor_id' => auth()->user()->vendor->id,
        ]);

        foreach ($this->estimate->estimate_sections as $section) {
            $new_section = $section->replicate();
            $new_section->estimate_id = $new_estimate->id;
            $new_section->bid_id = null;
            $new_section->total = 0;
            $new_section->save();

            foreach ($section->estimate_line_items as $estimate_line_item) {
                $new_line_item = new EstimateLineItem();
                $new_line_item->estimate_id = $new_estimate->id;
                $new_line_item->section_id = $new_section->id;
                $new_line_item->line_item_id = $estimate_line_item->line_item_id;
                $new_line_item->name = $estimate_line_item->name;
                $new_line_item->category = $estimate_line_item->category;
                $new_line_item->sub_category = $estimate_line_item->sub_category;
                $new_line_item->unit_type = $estimate_line_item->unit_type;
                $new_line_item->desc = $estimate_line_item->desc;
                $new_line_item->notes = $estimate_line_item->notes;
                $new_line_item->quantity = $estimate_line_item->quantity;

                if ($this->cost_mode === 'current') {
                    $template = $estimate_line_item->line_item;
                    $new_line_item->cost = $template->cost;
                    $new_line_item->total = $template->cost * $estimate_line_item->quantity;
                } else {
                    $new_line_item->cost = $estimate_line_item->cost;
                    $new_line_item->total = $estimate_line_item->cost * $estimate_line_item->quantity;
                }

                $new_line_item->save(); // Observer updates section total

                // Duplicate allowances
                if ($this->include_allowances) {
                    foreach ($estimate_line_item->allowances as $allowance) {
                        $new_line_item->allowances()->create([
                            'description' => $allowance->description,
                            'amount' => $allowance->amount,
                        ]);
                    }
                }
            }
        }

        $this->modal('estimate_duplicate_modal')->close();

        // $this->redirect(ProjectShow::class, navigate: true);
        return $this->redirect(route('projects.show', ['project' => $this->project_id]), navigate: true);

        Flux::toast(
            duration: 10000,
            position: 'top right',
            variant: 'success',
            heading: 'Estimate Duplicated',
            // route / href / wire:click
            // route: 'estimates/'.$new_estimate->id,
            text: '',
        );
    }

    public function render()
    {
        return view('livewire.estimates.duplicate');
    }
}

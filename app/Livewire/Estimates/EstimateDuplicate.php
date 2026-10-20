<?php

namespace App\Livewire\Estimates;

use App\Models\Client;
use App\Models\Estimate;
use App\Models\EstimateSection;
use App\Models\Project;
use App\Services\Estimates\EstimateDuplicator;

use Flux;

use App\Livewire\Projects\ProjectShow;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use Livewire\Attributes\Computed;

use Illuminate\Support\Facades\Log;

class EstimateDuplicate extends Component
{
    use AuthorizesRequests;

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

    public function duplicateToEstimateModal(int $sectionId)
    {
        // EstimateSection carries no tenant scope of its own: only accept a
        // section whose estimate is one EstimateScope says I can see.
        $section = EstimateSection::whereHas('estimate')->findOrFail($sectionId);

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

        // project_id is a plain client-writable property: resolve it through
        // ProjectScope so it can't be pointed at another tenant's project.
        $project = Project::query()->findOrFail($this->project_id);
        $this->authorize('create', [Estimate::class, $project]);

        if ($this->estimate_id === 'new') {
            $new_estimate = Estimate::create([
                'project_id' => $project->id,
                'belongs_to_vendor_id' => auth()->user()->vendor->id,
            ]);
        } else {
            // Prevent duplicating to the same estimate
            if ($this->estimate_id == $this->section->estimate_id) {
                throw new \Exception('Cannot duplicate section to the same estimate. Please select a different estimate.');
            }

            $new_estimate = Estimate::findOrFail($this->estimate_id);
        }

        $new_section = app(EstimateDuplicator::class)->duplicateSection(
            $this->section,
            $new_estimate,
            $this->cost_mode,
            $this->include_allowances,
        );

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

        // project_id is a plain client-writable property: resolve it through
        // ProjectScope so it can't be pointed at another tenant's project.
        $project = Project::query()->findOrFail($this->project_id);
        $this->authorize('create', [Estimate::class, $project]);

        $new_estimate = app(EstimateDuplicator::class)->duplicateEstimate(
            $this->estimate,
            $project,
            $this->cost_mode,
            $this->include_allowances,
        );

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

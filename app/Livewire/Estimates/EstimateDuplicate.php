<?php

namespace App\Livewire\Estimates;

use App\Models\Client;
use App\Models\Estimate;
use Livewire\Component;

class EstimateDuplicate extends Component
{
    public Estimate $estimate;

    public $clients = [];

    public $client_projects = [];

    public $client_id = null;

    public $project_id = null;

    public $modal_show = false;

    protected $listeners = ['duplicateModal'];

    protected function rules()
    {
        return [
            'client_id' => 'required',
            'project_id' => 'required',
        ];
    }

    public function mount()
    {
        $this->clients = Client::orderBy('created_at', 'DESC')->get();
    }

    public function updated($field, $value)
    {
        if ($field == 'client_id') {
            if ($value) {
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
        $this->modal_show = true;
    }

    public function save()
    {
        $this->validate();

        //get current estimate and duplicate sections and line_items
        $new_estimate = Estimate::create([
            'project_id' => $this->project_id,
            'belongs_to_vendor_id' => auth()->user()->vendor->id,
            // 'sections' => collect($this->estimate->sections)->toJson(),
        ]);

        foreach ($this->estimate->estimate_sections as $section) {
            $new_section = $section->replicate();
            $new_section->estimate_id = $new_estimate->id;
            $new_section->bid_id = null;
            $new_section->save();

            foreach ($this->estimate->estimate_line_items->where('section_id', $section->id) as $line_item) {
                $line_item->unsetEventDispatcher();
                $new_line_item = $line_item->replicate();
                $new_line_item->estimate_id = $new_estimate->id;
                $new_line_item->section_id = $new_section->id;
                $new_line_item->save();
            }
        }

        $this->dispatch('notify',
            type: 'success',
            content: 'Estimate Duplicated',
            route: 'estimates/'.$new_estimate->id
        );
    }

    public function render()
    {
        return view('livewire.estimates.duplicate');
    }
}

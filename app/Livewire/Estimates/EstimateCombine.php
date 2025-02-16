<?php

namespace App\Livewire\Estimates;

use App\Models\Client;
use App\Models\Estimate;
use App\Models\Project;
use Livewire\Component;

class EstimateCombine extends Component
{
    public Client $client;

    public $estimate_id = null;

    public $estimate = null;

    public $estimates = [];

    public $modal_show = false;

    protected $listeners = ['combineModal'];

    protected function rules()
    {
        return [
            'estimate_id' => 'required',
        ];
    }

    public function mount()
    {
        // $client_projects = $this->client->projects->pluck('id')->toArray();
        // $this->estimates = Estimate::whereIn('project_id', $client_projects)->with('project')->get();
        $this->estimates = Estimate::orderBy('created_at', 'DESC')->get();
        // dd($this->estimates);
    }

    public function combineModal($existing_estimate_id)
    {
        $this->estimates = $this->estimates->where('id', '!=', $existing_estimate_id);
        $this->estimate = Estimate::findOrFail($existing_estimate_id);
        $this->modal_show = true;
    }

    public function save()
    {
        $this->validate();
        $new_estimate = Estimate::findOrFail($this->estimate_id);

        //get current estimate and duplicate sections and line_items
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

        $this->modal_show = false;
    }

    public function render()
    {
        return view('livewire.estimates.combine');
    }
}

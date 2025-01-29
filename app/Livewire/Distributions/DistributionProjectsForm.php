<?php

namespace App\Livewire\Distributions;

use App\Models\Distribution;
use App\Models\Project;
use Livewire\Component;

class DistributionProjectsForm extends Component
{
    public Project $project;

    public $modal_show = false;

    public $distributions = [];

    public $percent_distributions_sum = 0;

    public $view_text = [
        'card_title' => 'New Distributions',
        'button_text' => 'Save Distributions',
        'form_submit' => 'store',
    ];

    protected $listeners = ['addDis'];

    protected function rules()
    {
        return [
            'distributions.*.percent' => 'nullable|numeric|min:10',
            'distributions.*.percent_amount' => 'nullable|numeric',
            'percent_distributions_sum' => 'required|numeric|min:100|max:100',
        ];
    }

    protected $messages =
        [
            'percent_distributions_sum' => 'Percent sum must equal to 100%',
            'expense_splits.*.amount.required_if' => 'The split amount field is required.',
            'expense_splits.*.amount.numeric' => 'The amount field must be numberic.',
        ];

    public function updated($field, $value)
    {
        $this->validateOnly($field);
        //distributions.0.percent
        $index = substr($field, 14, -8);
        if ($field == 'distributions.'.$index.'.percent') {
            if ($value == '' || $value == 0 || $value == 0.0 || $value == 0.00) {
                $this->distributions[$index]['percent'] = '';
                // $this->distributions[$index]['percent_amount'] = NULL;
            }
        }
    }

    public function mount()
    {
        $this->distributions = Distribution::all();
        $this->resetModal();
    }

    public function resetModal()
    {
        // Public functions should be reset here
        $this->distributions->each(function ($item, $key) {
            $item->percent = null;
            $item->percent_amount = null;
        });

        $this->percent_distributions_sum = 0;
    }

    public function getPercentSumProperty()
    {
        $this->percent_distributions_sum =
            collect($this->distributions)
                ->reject(function ($distribution) {
                    return ! $distribution->percent;
                })->sum('percent');

        foreach ($this->distributions as $distribution) {
            if ($distribution->percent != '' && $distribution->percent != 0 && $distribution->percent != null) {
                $percent = '.'.$distribution->percent;
                $distribution->percent_amount = round($this->project->finances['profit'] * $percent, 2);
            } else {
                $distribution->percent_amount = null;
            }
        }

        return $this->percent_distributions_sum;
    }

    public function addDis(Project $project)
    {
        $this->project = $project;
        $this->resetModal();
        $this->modal_show = true;
    }

    public function store()
    {
        $this->validate();

        foreach ($this->distributions->whereNotNull('percent') as $distribution) {
            $distribution->projects()->attach($this->project->id, [
                'percent' => $distribution->percent,
                'amount' => $distribution->percent_amount,
            ]);
        }

        $this->modal_show = false;
        //emit and refresh so distributions.index removes/refreshes projects_doesnt_dis
        $this->dispatch('refreshComponent')->to('distributions.distributions-index');
        //reset modal data
        // $this->dispatch('resetModal')->self();

        //NOTIFICATIONS!
    }

    public function render()
    {
        return view('livewire.distributions.projects-form', [
        ]);
    }
}

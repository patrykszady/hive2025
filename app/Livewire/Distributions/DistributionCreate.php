<?php

namespace App\Livewire\Distributions;

use App\Livewire\Forms\DistributionForm;
use App\Models\Distribution;
use Livewire\Component;

class DistributionCreate extends Component
{
    public DistributionForm $form;

    public $view_text = [
        'card_title' => 'Create Distribution',
        'button_text' => 'Create',
        'form_submit' => 'save',
    ];

    protected $listeners = ['newDistribution'];

    public function mount()
    {
        $vendor = auth()->user()->vendor;

        $this->form->users = $vendor->users()->whereDoesntHave('distributions')->employed()->wherePivot('role_id', 1)->get();
    }

    public function newDistribution()
    {
        $this->modal('distribution_form_modal')->show();
    }

    public function updated($field, $value)
    {
        if ($field == 'form.user_id') {
            $user_first_name = $this->form->users->where('id', $value)->first()->first_name;
            $this->form->name = $user_first_name.' - Home';
        }

        // $this->validateOnly($field);
    }

    public function save()
    {
        // $this->form->validate();
        $distribution = $this->form->store();

        //12-30-23 why not just refreshComponent => $refresh
        $this->dispatch('refreshForce')->to('distributions.distributions-list');
        $this->modal('distribution_form_modal')->close();

        $this->dispatch('notify',
            type: 'success',
            content: 'Distribution Created',
            route: 'distributions/'.$distribution->id
        );

        $this->form->reset();
    }

    public function render()
    {
        $this->authorize('viewAny', Distribution::class);

        return view('livewire.distributions.form');
    }
}

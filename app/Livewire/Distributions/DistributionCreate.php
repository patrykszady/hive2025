<?php

namespace App\Livewire\Distributions;

use App\Livewire\Forms\DistributionForm;
use App\Models\Distribution;
use Flux;
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
    }

    public function save()
    {
        $distribution = $this->form->store();

        $this->dispatch('refreshComponent')->to('distributions.distributions-list');

        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'Distribution Created.',
            // route / href / wire:click
            text: 'Distribution ' . $distribution->name . ' created successfully.',
        );

        $this->modal('distribution_form_modal')->close();
        $this->form->reset();
    }

    public function render()
    {
        $this->authorize('viewAny', Distribution::class);

        return view('livewire.distributions.form');
    }
}

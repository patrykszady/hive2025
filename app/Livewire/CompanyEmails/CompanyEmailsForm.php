<?php

namespace App\Livewire\CompanyEmails;

use App\Models\CompanyEmail;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Livewire\Component;

class CompanyEmailsForm extends Component
{
    use AuthorizesRequests;

    public $modal_show = null;

    public $email = null;

    protected $listeners = ['addEmail'];

    protected function rules()
    {
        return [
            'email' => [
                'required',
                'email',
                'min:6',
                Rule::unique('company_emails', 'email'),
            ],
        ];
    }

    public function updated($field)
    {
        $this->validateOnly($field);
    }

    public function addEmail()
    {
        $this->email = null;
        $this->modal_show = true;
    }

    public function mount()
    {
        $this->view_text = [
            'card_title' => 'Add Email',
            'button_text' => 'Add Email',
            'form_submit' => 'store',
        ];
    }

    public function store()
    {
        $this->validate();

        CompanyEmail::create([
            'email' => $this->email,
            'vendor_id' => auth()->user()->vendor->id,
        ]);

        $this->dispatch('refreshComponent')->to('company-emails.company-emails-index');
        $this->dispatch('confirmProcessStep', 'emails_registered')->to('entry.vendor-registration');
        $this->modal_show = false;
    }

    public function render()
    {
        return view('livewire.company-emails.form');
    }
}

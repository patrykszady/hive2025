<?php

namespace App\Livewire\CompanyEmails;

use App\Models\CompanyEmail;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Title;
use Livewire\Component;

class CompanyEmailsIndex extends Component
{
    use AuthorizesRequests;

    protected $listeners = ['refreshComponent' => '$refresh'];

    public $view = null;

    public $email_accounts = [];

    public function mount()
    {
        $this->email_accounts =
            CompanyEmail::all()->each(function ($email, $key) {
                if (is_null($email->api_json['errors'])) {
                    $email->status = 'Connected';
                } else {
                    $email->status = 'Error';
                }
            });
    }

    #[Title('Email Accounts')]
    public function render()
    {
        $this->authorize('viewAny', CompanyEmail::class);

        return view('livewire.company-emails.index');
    }
}

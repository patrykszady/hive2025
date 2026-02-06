<?php

namespace App\Livewire\Clients;

use App\Models\Client;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

class ClientsIndex extends Component
{
    use AuthorizesRequests;

    #[Url(except: '')]
    public string $client_name_search = '';

    protected $listeners = ['refreshComponent' => '$refresh'];

    #[Title('Clients')]
    public function render()
    {
        $this->authorize('viewAny', Client::class);

        return view('livewire.clients.index');
    }
}

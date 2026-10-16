<?php

namespace App\Livewire\Clients;

use App\Models\Client;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use App\Livewire\Concerns\RemembersFilters;

class ClientsIndex extends Component
{
    use AuthorizesRequests, RemembersFilters;

    #[Url(except: '')]
    public string $client_name_search = '';

    protected $listeners = ['refreshComponent' => '$refresh'];

    /**
     * The filters this page keeps between visits (see RemembersFilters).
     *
     * @return list<string>
     */
    protected function rememberedFilters(): array
    {
        return ['client_name_search'];
    }

    #[Title('Clients')]
    public function render()
    {
        $this->authorize('viewAny', Client::class);

        return view('livewire.clients.index');
    }
}

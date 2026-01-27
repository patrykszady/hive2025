<?php

namespace App\Livewire\Clients;

use App\Models\Client;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Lazy]
class ClientsIndex extends Component
{
    use AuthorizesRequests, WithPagination;

    public $client_name_search = '';

    public $view;

    protected $listeners = ['refreshComponent' => '$refresh'];

    protected $queryString = [
        'client_name_search' => ['except' => ''],
    ];

    public $sortBy = 'created_at';

    public $sortDirection = 'desc';

    public function updating($field)
    {
        $this->resetPage();
    }

    public function sort($column)
    {
        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        }
    }

    #[Computed]
    public function clients()
    {
        $clients = Client::when($this->client_name_search, function ($query) {
            $search = $this->client_name_search;

            return $query->where(function ($query) use ($search) {
                $query->where('business_name', 'like', "%{$search}%")
                    ->orWhere('address', 'like', "%{$search}%")
                    ->orWhere('address_2', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%")
                    ->orWhere('state', 'like', "%{$search}%")
                    ->orWhere('zip_code', 'like', "%{$search}%")
                    ->orWhereHas('users', function ($query) use ($search) {
                        $query->where('last_name', 'like', "%{$search}%")
                            ->orWhere('first_name', 'like', "%{$search}%");
                    });
            });
        })
            ->tap(fn ($query) => $this->sortBy ? $query->orderBy($this->sortBy, $this->sortDirection) : $query)
            ->paginate(10);

        return $clients;
    }

    #[Title('Clients')]
    public function render()
    {
        $this->authorize('viewAny', Client::class);
        return view('livewire.clients.index');
    }
}

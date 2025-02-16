<?php

namespace App\Livewire\Clients;

use App\Models\Client;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
// use Livewire\Attributes\Lazy;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

// #[Lazy]
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
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'asc';
        }
    }

    #[Computed]
    public function clients()
    {
        $clients =
            Client::when($this->client_name_search, function ($query) {
                return $query->whereHas('users', function ($query) {
                    return $query->where('last_name', 'like', "%{$this->client_name_search}%")
                        ->orWhere('first_name', 'like', "%{$this->client_name_search}%");
                });
            })
                ->orWhere('address', 'like', "%{$this->client_name_search}%")
                ->orWhere('business_name', 'like', "%{$this->client_name_search}%")
                ->tap(fn ($query) => $this->sortBy ? $query->orderBy($this->sortBy, $this->sortDirection) : $query)
                ->paginate(10);

        return $clients;
    }

    #[Title('Clients')]
    public function render()
    {
        return view('livewire.clients.index');
    }
}

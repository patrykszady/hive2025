<?php

namespace App\Livewire\Clients;

use App\Models\Client;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Reactive;
use Livewire\Component;
use Livewire\WithPagination;

class ClientsTable extends Component
{
    use WithPagination;

    #[Reactive]
    public string $clientNameSearch = '';

    public string $sortBy = 'created_at';

    public string $sortDirection = 'desc';

    public function updating($field): void
    {
        $this->resetPage();
    }

    public function sort(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'desc';
        }
    }

    #[Computed]
    public function clients()
    {
        $search = trim($this->clientNameSearch);
        if ($search !== '' && strlen($search) < 2) {
            $search = '';
        }

        return Client::scopedSearch($search, [], $this->sortBy, $this->sortDirection)
            ->query(function ($query) {
                $query->with('users');
            })
            ->paginate(10);
    }

    public function render()
    {
        return view('livewire.clients.clients-table');
    }

    public function placeholder()
    {
        return view('livewire.clients.clients-table-placeholder');
    }
}

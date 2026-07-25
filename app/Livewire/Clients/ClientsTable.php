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

    /**
     * How many skeleton rows the loading placeholder should paint — the card's
     * page size, so the skeleton is the same height as the table that replaces
     * it (no jump on load). Callers that can cheaply COUNT the real rows pass
     * the smaller of the two.
     */
    public static function placeholderRows(): int
    {
        return 10;
    }

    /**
     * Column defs for the clients table — the loading skeleton renders from the
     * same array as the real header row, so widths can never drift apart.
     *
     * @return array<int, array{label: string, width: string, skeleton?: string, skeletonWidth?: string}>
     */
    public static function columnDefs(): array
    {
        return [
            ['label' => 'Name', 'width' => 'w-[35%] min-w-0', 'skeletonWidth' => 'w-32'],
            ['label' => 'Address', 'width' => 'w-[45%] min-w-0', 'skeletonWidth' => 'w-40'],
            ['label' => 'Created', 'width' => 'w-[20%]', 'skeletonWidth' => 'w-20'],
        ];
    }

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

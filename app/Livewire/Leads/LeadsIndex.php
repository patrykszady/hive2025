<?php

namespace App\Livewire\Leads;

use App\Models\Lead;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

class LeadsIndex extends Component
{
    use AuthorizesRequests, WithPagination;

    public $origin = '';

    public $view = null;

    public $sortBy = 'date';

    public $sortDirection = 'desc';

    protected $queryString = [
        'origin' => ['except' => ''],
    ];

    protected $listeners = ['refreshComponent' => '$refresh'];

    #[Computed]
    public function leads()
    {
        $leads =
            Lead::with(['user', 'last_status'])->when($this->origin, function ($query) {
                return $query->where('origin', $this->origin);
            })
                ->orderBy($this->sortBy, $this->sortDirection)
                ->paginate(15);

        return $leads;
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

    #[Title('Leads')]
    public function render()
    {
        $this->authorize('viewAny', Lead::class);

        return view('livewire.leads.index');
    }
}

<?php

namespace App\Livewire\Payments;

use App\Models\Payment;
use App\Models\Project;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

class PaymentsIndex extends Component
{
    use AuthorizesRequests, WithPagination;

    public Project $project;

    public $view = null;

    public $sortBy = 'date';

    public $sortDirection = 'desc';

    // public function mount(){
    //     dd($this->project);
    // }

    #[Computed]
    public function payments()
    {
        if (isset($this->project)) {
            $payments =
                $this->project->payments()->tap(fn ($query) => $this->sortBy ? $query->orderBy($this->sortBy, $this->sortDirection) : $query)
                    ->paginate(15);
            // Payment::where('project_id', $this->project->id)->tap(fn ($query) => $this->sortBy ? $query->orderBy($this->sortBy, $this->sortDirection) : $query)
            // ->paginate(10);

            // dd($payments);
        } else {
            $payments =
                Payment::tap(fn ($query) => $this->sortBy ? $query->orderBy($this->sortBy, $this->sortDirection) : $query)
                    ->paginate(10);
        }

        return $payments;
    }

    public function sort($column)
    {
        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'asc' : 'desc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'asc';
        }
    }

    #[Title('Payments')]
    public function render()
    {
        return view('livewire.payments.index');
    }
}

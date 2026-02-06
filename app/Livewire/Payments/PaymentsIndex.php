<?php

namespace App\Livewire\Payments;

use App\Livewire\Concerns\HasToJsonMethod;
use App\Models\Payment;
use App\Models\Project;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

class PaymentsIndex extends Component
{
    use AuthorizesRequests, WithPagination, HasToJsonMethod;

    public Project $project;

    public $view = null;

    public $sortBy = 'date';
    public $sortDirection = 'desc';

    protected $listeners = ['refreshComponent' => '$refresh'];

    #[Computed]
    public function hasClientsWithProjects()
    {
        return \App\Models\Client::withWhereHas('projects', function ($query) {
            $query->status([6, 7, 8]); // Active, Complete, Service Call
        })->exists();
    }

    #[Computed]
    public function payments()
    {
        // Get payments without transactions
        // $no_transaction_payments = Payment::whereNull('transaction_id')->where('date', '>', '2018-01-03')->where('reference', '!=', 'Cash')->get();
        // dd($no_transaction_payments);
            
        // Start with the base query based on view
        $query = in_array($this->view, ['projects.show', 'estimate.pdf'])
            ? $this->project->payments()
            : Payment::query();

        // Apply sorting if specified
        if ($this->sortBy) {
            $query->orderBy($this->sortBy, $this->sortDirection);
        }

        // Apply pagination with different limits based on view
        // For PDF export, don't paginate - get all results
        if ($this->view === 'estimate.pdf') {
            return $query->get();
        }
        
        return $query->paginate(in_array($this->view, ['projects.show']) ? 25 : 15);
    }

    public function sort($column)
    {
        if ($this->sortBy === $column) {
            // Toggle between asc and desc
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'asc';
        }
    }

    #[Title('Payments')]
    public function render()
    {
        $this->authorize('viewAny', Payment::class);
        return view('livewire.payments.index');
    }

    public function placeholder()
    {
        return view('livewire.payments.payments-index-placeholder');
    }
}

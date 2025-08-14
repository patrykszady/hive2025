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

    #[Computed]
    public function payments()
    {
        // Get payments without transactions
        // $no_transaction_payments = Payment::whereNull('transaction_id')->where('date', '>', '2018-01-03')->where('reference', '!=', 'Cash')->get();
        // dd($no_transaction_payments);
            
        // Start with the base query based on view
        $query = $this->view == 'projects.show'
            ? $this->project->payments()
            : Payment::query();

        // Apply sorting if specified
        if ($this->sortBy) {
            $query->orderBy($this->sortBy, $this->sortDirection);
        }

        // Apply pagination with different limits based on view
        return $query->paginate($this->view == 'projects.show' ? 25 : 15);
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
}

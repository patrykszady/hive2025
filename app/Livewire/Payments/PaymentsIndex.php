<?php

namespace App\Livewire\Payments;

use App\Livewire\Concerns\HasToJsonMethod;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Project;
use Illuminate\Support\Facades\Cache;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class PaymentsIndex extends Component
{
    use AuthorizesRequests, WithPagination, HasToJsonMethod;

    /**
     * How many skeleton rows the loading placeholder should paint — the card's
     * page size, so the skeleton is the same height as the table that replaces
     * it (no jump on load). Callers that can cheaply COUNT the real rows pass
     * the smaller of the two.
     */
    public static function placeholderRows(?string $view = null): int
    {
        return $view === 'projects.show' ? 25 : 15;
    }

    /**
     * Column defs for the payments table — the loading skeleton renders from
     * the same array as the real header row, so widths can never drift apart.
     * Columns vary by view, so the caller passes the same $view the table uses.
     *
     * @return array<int, array{label: string, width: string, skeleton?: string, skeletonWidth?: string}>
     */
    public static function columnDefs(?string $view = null): array
    {
        // Embedded on projects.show / estimate.pdf: no Project or Client
        // column, and the real table carries no % widths — table-fixed splits
        // the four remaining cells evenly.
        if (in_array($view, ['projects.show', 'estimate.pdf'], true)) {
            return [
                ['label' => 'Amount', 'width' => 'w-1/4', 'skeletonWidth' => 'w-16'],
                ['label' => 'Date', 'width' => 'w-1/4', 'skeletonWidth' => 'w-20'],
                ['label' => 'Reference', 'width' => 'w-1/4 min-w-0', 'skeletonWidth' => 'w-20'],
                ['label' => 'Status', 'width' => 'w-1/4', 'skeleton' => 'badge'],
            ];
        }

        return [
            ['label' => 'Amount', 'width' => 'w-[13%]', 'skeletonWidth' => 'w-16'],
            ['label' => 'Date', 'width' => 'w-[12%]', 'skeletonWidth' => 'w-16'],
            ['label' => 'Project', 'width' => 'w-[25%] min-w-0', 'skeletonWidth' => 'w-32'],
            ['label' => 'Client', 'width' => 'w-[16%] min-w-0', 'skeletonWidth' => 'w-24'],
            ['label' => 'Reference', 'width' => 'w-[18%] min-w-0', 'skeletonWidth' => 'w-24'],
            ['label' => 'Status', 'width' => 'w-[16%]', 'skeleton' => 'badge'],
        ];
    }

    public Project $project;

    public $clients = [];

    public $projects = [];

    #[Url(except: '')]
    public $amount = '';

    #[Url(except: '')]
    public $project_filter = '';

    #[Url(except: '')]
    public $client_filter = '';

    #[Url(except: '')]
    public $status_filter = '';

    public $view = null;

    public $vendor_filter = null;

    public $sortBy = 'date';
    public $sortDirection = 'desc';

    protected $listeners = ['refreshComponent' => '$refresh'];

    public function updating($field)
    {
        $this->resetPage();
    }

    public function mount(): void
    {
        if ($this->view !== null) {
            return;
        }

        $vendorId = auth()->user()?->vendor?->id;
        if (! $vendorId) {
            return;
        }

        $this->projects = Cache::remember("filters:v{$vendorId}:payment-projects", 600, function () use ($vendorId) {
            return Project::where('belongs_to_vendor_id', $vendorId)
                ->whereHas('payments')
                ->orderByDesc('created_at')
                ->get(['id', 'project_name', 'address', 'client_id']);
        });

        $this->clients = Cache::remember("filters:v{$vendorId}:payment-clients", 600, function () use ($vendorId) {
            return Client::whereHas('projects.payments', function ($query) use ($vendorId) {
                $query->where('belongs_to_vendor_id', $vendorId);
            })
                ->with('users:id,first_name,last_name')
                ->orderBy('business_name')
                ->orderBy('id')
                ->get(['id', 'business_name']);
        });
    }

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

        $amount = trim((string) $this->amount);
        $projectId = is_numeric($this->project_filter) ? (int) $this->project_filter : null;
        $clientId = is_numeric($this->client_filter) ? (int) $this->client_filter : null;

        if ($amount !== '') {
            $query->where('amount', 'like', $amount . '%');
        }

        if ($projectId) {
            $query->where('project_id', $projectId);
        }

        if ($clientId) {
            $query->whereHas('project', function ($projectQuery) use ($clientId) {
                $projectQuery->where('client_id', $clientId);
            });
        }

        if ($this->vendor_filter) {
            // Payments for a specific (sub) vendor on this project: either recorded
            // under that vendor, or paid to them by check. PaymentScope pins queries
            // to the auth user's vendor, so it must be lifted for this filter.
            $vendorId = (int) $this->vendor_filter;
            $query->withoutGlobalScope(\App\Scopes\PaymentScope::class)
                ->where(function ($q) use ($vendorId) {
                    $q->where('belongs_to_vendor_id', $vendorId)
                        ->orWhereIn('check_id', function ($sub) use ($vendorId) {
                            $sub->select('id')->from('checks')->where('vendor_id', $vendorId);
                        });
                });
        }

        if ($this->status_filter === 'complete') {
            $query->whereNotNull('transaction_id');
        } elseif ($this->status_filter === 'missing') {
            $query->whereNull('transaction_id');
        }

        $query->with(['project.client']);

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

    public function placeholder(array $params = [])
    {
        // Livewire hands the lazy component's mount params to placeholder()
        // before mount() runs, so $view still holds its default here. Pass the
        // incoming view under its own key — Livewire re-merges the component's
        // public properties into the placeholder view data afterwards, which
        // would clobber a 'view' key.
        return view('livewire.payments.payments-index-placeholder', [
            'tableView' => $params['view'] ?? $this->view,
        ]);
    }
}

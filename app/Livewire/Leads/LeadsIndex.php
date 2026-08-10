<?php

namespace App\Livewire\Leads;

use App\Models\Client;
use App\Models\Lead;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class LeadsIndex extends Component
{
    use AuthorizesRequests, WithPagination;

    #[Url(except: '')]
    public $origin = '';

    /** @var array<string> Multi-select like the expenses Status filter. */
    #[Url(except: [])]
    public array $statuses = [];

    #[Url(except: '')]
    public $search = '';

    public $view = null;

    public $sortBy = 'date';

    public $sortDirection = 'desc';

    /** @var array<int> Selected lead IDs for bulk actions. */
    public array $selected = [];

    /**
     * Deep link from a notification: /leads?lead={id} opens that lead's
     * modal on arrival, instead of leaving the visitor to hunt the row.
     */
    public function mount(): void
    {
        $leadId = (int) request()->query('lead');

        if ($leadId > 0 && Lead::whereKey($leadId)->exists()) {
            $this->dispatch('editLead', lead: $leadId)->to('leads.lead-create');
        }
    }

    /**
     * How many skeleton rows the loading placeholder should paint — the card's
     * page size, so the skeleton is the same height as the table that replaces
     * it (no jump on load). Callers that can cheaply COUNT the real rows pass
     * the smaller of the two.
     */
    public static function placeholderRows(): int
    {
        return 15;
    }

    /**
     * Skeleton rows for THIS render: a cheap COUNT of what the table is about
     * to show (same origin/status/search filters, no pagination or eager
     * loads), capped at the page size. One lead gets one shimmering row —
     * not fifteen fakes that vanish a moment later.
     */
    public function skeletonRows(): int
    {
        $count = Lead::query()
            ->when($this->origin, fn ($query) => $query->where('origin', $this->origin))
            ->when(! empty($this->statuses), fn ($query) => $query->whereLatestStatus($this->statuses))
            ->when(trim((string) $this->search), function ($query, $term) {
                $term = mb_strtolower($term);
                $query->where(function ($q) use ($term) {
                    $q->whereRaw('LOWER(lead_data) like ?', ["%{$term}%"])
                        ->orWhereRaw('LOWER(notes) like ?', ["%{$term}%"]);
                });
            })
            ->count();

        return min($count, static::placeholderRows());
    }

    /**
     * Column defs for the leads table — the real header row AND the loading
     * skeleton render from this one array, so widths can never drift apart.
     *
     * @return array<int, array{label: string, width: string, skeleton?: string, skeletonWidth?: string}>
     */
    public static function columnDefs(): array
    {
        return [
            ['label' => 'Date', 'width' => 'w-[14%]', 'skeletonWidth' => 'w-12'],
            ['label' => 'Client', 'width' => 'w-[17%] min-w-0', 'skeletonWidth' => 'w-20'],
            // Status is a compact badge trigger — "Not a Fit ⌄" is the widest.
            ['label' => 'Status', 'width' => 'w-[12%]', 'skeleton' => 'badge'],
            ['label' => 'Last', 'width' => 'w-[10%]', 'skeletonWidth' => 'w-12'],
            ['label' => 'Origin', 'width' => 'w-[16%] min-w-0', 'skeletonWidth' => 'w-14'],
            ['label' => 'Address', 'width' => 'w-[31%] min-w-0', 'skeletonWidth' => 'w-28'],
        ];
    }

    #[On('refreshComponent')]
    public function refreshList(): void
    {
        unset($this->leads);
    }

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    public function updateLeadStatus(int $leadId, string $title): void
    {
        $this->authorize('viewAny', Lead::class);

        if (! in_array($title, array_column(Lead::selectableStatuses(), 'code'), true)) {
            return;
        }

        // LeadScope keeps this to the current vendor's leads.
        $lead = Lead::findOrFail($leadId);

        $this->applyStatus($lead, $title);

        unset($this->leads);
        $this->dispatch('lead-status-updated');
    }

    public function bulkSetStatus(string $title): void
    {
        $this->authorize('viewAny', Lead::class);

        if (empty($this->selected)
            || ! in_array($title, array_column(Lead::selectableStatuses(), 'code'), true)) {
            return;
        }

        $leads = Lead::whereIn('id', $this->selected)->get();

        foreach ($leads as $lead) {
            $this->applyStatus($lead, $title);
        }

        $this->selected = [];
        unset($this->leads);
        $this->dispatch('lead-status-updated');

        \Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: $leads->count().' '.str('lead')->plural($leads->count()).' set to '.$title.'.',
            text: '',
        );
    }

    protected function applyStatus(Lead $lead, string $title): void
    {
        $lead->setStatus($title);
    }

    public bool $showBulkDelete = false;

    public function confirmBulkDelete(): void
    {
        $this->authorize('viewAny', Lead::class);

        if (empty($this->selected)) {
            return;
        }

        $this->showBulkDelete = true;
    }

    /**
     * Aggregate delete impact for the confirmation modal: orphaned client
     * records and contacts that go away with the selected leads (each lead
     * assessed by Lead::deleteImpact()).
     *
     * @return array{count: int, clients: array<int, string>, users: array<int, string>}
     */
    #[Computed]
    public function bulkDeleteImpact(): array
    {
        // LeadScope keeps this to the current vendor's leads.
        $leads = Lead::whereIn('id', $this->selected)->get();

        $clients = [];
        $users = [];
        $holding = [];

        foreach ($leads as $lead) {
            $impact = $lead->deleteImpact();
            array_push($clients, ...$impact['clients']);

            if ($impact['user']) {
                $users[] = $impact['user'];
            }

            // A homeowner may be holding this lead's scheduling link or an
            // appointment booked through it — name them before the delete.
            if ($impact['schedule_link'] || $impact['booked_consult']) {
                $holding[] = trim((string) ($lead->lead_data['name'] ?? '')) ?: 'Lead #'.$lead->id;
            }
        }

        return [
            'count' => $leads->count(),
            'clients' => array_values(array_unique($clients)),
            'users' => array_values(array_unique($users)),
            'holding' => array_values(array_unique($holding)),
        ];
    }

    public function bulkDelete(): void
    {
        $this->authorize('viewAny', Lead::class);
        $this->showBulkDelete = false;

        if (empty($this->selected)) {
            return;
        }

        $leads = Lead::whereIn('id', $this->selected)->get();

        foreach ($leads as $lead) {
            $lead->deleteWithOrphans();
        }

        $this->selected = [];
        unset($this->leads, $this->bulkDeleteImpact);
        $this->dispatch('lead-status-updated');

        \Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: $leads->count().' '.str('lead')->plural($leads->count()).' deleted.',
            text: '',
        );
    }

    public function updating($name, $value): void
    {
        if (in_array(strtok($name, '.'), ['origin', 'statuses', 'search'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function leads()
    {
        $leads =
            Lead::with(['user.clients', 'last_status'])->when($this->origin, function ($query) {
                return $query->where('origin', $this->origin);
            })
                ->when(! empty($this->statuses), function ($query) {
                    return $query->whereLatestStatus($this->statuses);
                })
                ->when(trim((string) $this->search), function ($query, $term) {
                    // lead_data is JSON, so a raw LIKE covers name, address,
                    // email and phone in one shot. JSON columns use a binary
                    // collation (case-sensitive LIKE) — LOWER() both sides so
                    // "steve" finds "Steve".
                    $term = mb_strtolower($term);
                    $query->where(function ($q) use ($term) {
                        $q->whereRaw('LOWER(lead_data) like ?', ["%{$term}%"])
                            ->orWhereRaw('LOWER(notes) like ?', ["%{$term}%"]);
                    });
                })
                ->orderBy($this->sortBy, $this->sortDirection)
                ->paginate(15);

        return $leads;
    }

    /** @return array<int, string> */
    #[Computed]
    public function origins(): array
    {
        return Lead::query()
            ->whereNotNull('origin')
            ->where('origin', '!=', '')
            ->distinct()
            ->orderBy('origin')
            ->pluck('origin')
            ->all();
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

    /** Delegates to the model so the table, backfill command and job agree. */
    public function clientForLead(Lead $lead): ?Client
    {
        return $lead->resolveClient();
    }

    #[Title('Leads')]
    public function render()
    {
        $this->authorize('viewAny', Lead::class);

        return view('livewire.leads.index');
    }
}

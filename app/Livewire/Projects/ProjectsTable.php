<?php

namespace App\Livewire\Projects;

use App\Models\Project;
use App\Models\ProjectStatus;
use Flux;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Reactive;
use Livewire\Component;
use Livewire\WithPagination;

class ProjectsTable extends Component
{
    use WithPagination;

    #[Reactive]
    public $projectNameSearch = '';
    #[Reactive]
    public $clientId = null;
    #[Reactive]
    public $clientVendorId = null;
    #[Reactive]
    public $projectStatusTitle = null;
    #[Reactive]
    public $view = null;

    public array $statusChangedProjectIds = [];

    /**
     * How many skeleton rows the loading placeholder should paint — the card's
     * page size, so the skeleton is the same height as the table that replaces
     * it (no jump on load). Callers that can cheaply COUNT the real rows pass
     * the smaller of the two.
     */
    public static function placeholderRows(): int
    {
        return 20;
    }

    /**
     * Column defs for the projects table — the loading skeleton renders from the
     * same array the real header row mirrors, so widths can never drift apart.
     * Columns vary by view and by client-portal browsing, so callers pass the
     * same $view the table renders with.
     *
     * @return array<int, array{label: string, width: string, skeleton?: string, skeletonWidth?: string}>
     */
    public static function columnDefs(?string $view = null, ?bool $browsingAsClient = null): array
    {
        $browsingAsClient ??= (bool) (auth()->user()?->is_browsing_as_client);

        if ($view === 'clients.index') {
            $columns = [
                ['label' => 'Name', 'width' => 'w-[30%] min-w-0', 'skeletonWidth' => 'w-28'],
                ['label' => 'Address', 'width' => 'w-[30%] min-w-0', 'skeletonWidth' => 'w-32'],
            ];
        } else {
            $columns = [
                ['label' => 'Address', 'width' => 'w-[30%] min-w-0', 'skeletonWidth' => 'w-32'],
            ];

            if (! $browsingAsClient) {
                $columns[] = ['label' => 'Client', 'width' => 'w-[25%] min-w-0', 'skeletonWidth' => 'w-24'];
            }

            $columns[] = ['label' => 'Name', 'width' => 'w-[25%] min-w-0', 'skeletonWidth' => 'w-28'];
        }

        if ($browsingAsClient) {
            $columns[] = ['label' => 'Contractor', 'width' => 'w-[25%] min-w-0', 'skeletonWidth' => 'w-24'];
        }

        $columns[] = ['label' => 'Status', 'width' => 'w-[30%] min-w-[5rem] shrink-0', 'skeleton' => 'badge'];

        return $columns;
    }

    public function updating($field): void
    {
        $this->resetPage();
    }

    public function getPageName(): string
    {
        return 'projects_page';
    }

    #[Computed]
    public function projects()
    {
        $clientIds = [];
        $selectedClientId = null;

        if ($this->clientId !== null && $this->clientId !== '') {
            $selectedClientId = (int) $this->clientId;
        } elseif (! empty($this->clientVendorId)) {
            $clientIds = Project::where('belongs_to_vendor_id', (int) $this->clientVendorId)
                ->pluck('client_id')
                ->toArray();
        }

        if (auth()->user()->is_browsing_as_client) {
            // Only include personal clients — exclude clients whose vendor_id
            // matches a vendor the user belongs to (those are vendor accounts, not client accounts)
            $userVendorIds = auth()->user()->vendors()
                ->withoutGlobalScopes()
                ->pluck('vendors.id')
                ->toArray();

            $userClientIds = auth()->user()->clients()
                ->where(function ($query) use ($userVendorIds) {
                    $query->whereNull('clients.vendor_id')
                        ->orWhereNotIn('clients.vendor_id', $userVendorIds);
                })
                ->pluck('clients.id')
                ->toArray();
            if (empty($clientIds)) {
                $clientIds = $userClientIds;
            } else {
                $clientIds = array_values(array_intersect($clientIds, $userClientIds));
            }
        }

        $projectSearch = trim((string) $this->projectNameSearch);
        if ($projectSearch !== '' && strlen($projectSearch) < 2) {
            $projectSearch = '';
        }

        $filters = [];
        $currentVendorId = (int) (auth()->user()->vendor?->id ?? 0);

        if ($selectedClientId !== null) {
            $selectedClient = \App\Models\Client::find($selectedClientId);

            if ($selectedClient && $currentVendorId && (int) $selectedClient->vendor_id === $currentVendorId) {
                // Selected client is owned by the current vendor — only projects
                // linked via the project_vendor pivot for THIS vendor + THIS client.
                $filters[] = 'vendor_client_pairs = "'.$currentVendorId.'_'.$selectedClientId.'"';
            } else {
                // Otherwise: project owns the client OR pivot row references it.
                $filters[] = '(client_id = '.$selectedClientId.' OR pivot_client_ids = '.$selectedClientId.')';
            }
        } elseif (! empty($clientIds)) {
            $filters[] = 'client_id IN ['.implode(',', array_map('intval', $clientIds)).']';
        }

        $statusCodes = $this->projectStatusTitle !== null && $this->projectStatusTitle !== '' ? [$this->projectStatusTitle] : [];
        // Derived, not hardcoded: a literal list here silently dropped the
        // Consult status (9) when it was added.
        $validCodes = array_column(\App\Models\ProjectStatus::selectableStatuses(), 'code');

        $codes = [];
        if (! empty($statusCodes)) {
            $codes = collect($statusCodes)
                ->filter(fn($code) => $code !== '' && $code !== null)
                ->flatMap(function ($code) {
                    if ((int) $code === 7) {
                        return [7, 8];
                    }
                    return [(int) $code];
                })
                ->filter(fn($code) => in_array($code, $validCodes, true))
                ->unique()
                ->values()
                ->all();
        }

        if (! empty($codes) && $currentVendorId) {
            $vendorStatusFilters = collect($codes)
                ->map(fn($code) => '"'.$currentVendorId.'_'.$code.'"')
                ->implode(', ');
            $filters[] = 'vendor_status_codes IN ['.$vendorStatusFilters.']';
        }

        $results = Project::scopedSearch($projectSearch, $filters, 'latest_status_date', 'desc')
            ->query(function ($query) {
                $query->with(['statuses', 'client.users', 'createdByVendor']);
            })
            ->paginate(20, pageName: $this->getPageName());

        // Filter out projects whose status just changed (Meilisearch may not have indexed yet)
        if (! empty($this->statusChangedProjectIds) && ! empty($statusCodes)) {
            $removed = $this->statusChangedProjectIds;
            $results->setCollection(
                $results->getCollection()->reject(fn ($p) => in_array($p->id, $removed))
            );
        }

        return $results;
    }

    public function updateProjectStatus(int $projectId, int $statusCode): void
    {
        if (auth()->user()?->is_browsing_as_client) {
            abort(403);
        }

        $project = Project::findOrFail($projectId);
        $this->authorize('update', $project);

        // Derived, not hardcoded: a literal list here silently dropped the
        // Consult status (9) when it was added.
        $validCodes = array_column(\App\Models\ProjectStatus::selectableStatuses(), 'code');
        if (! in_array($statusCode, $validCodes, true)) {
            return;
        }

        ProjectStatus::create([
            'project_id' => $project->id,
            'belongs_to_vendor_id' => auth()->user()->vendor->id,
            'status_code' => $statusCode,
            'start_date' => today()->format('Y-m-d'),
        ]);

        if ($statusCode === 10) {
            $project->estimates()->delete();
        }

        $project->searchable();

        $this->statusChangedProjectIds[] = $projectId;
        unset($this->projects);

        Flux::toast('Status updated.');
    }

    public function render()
    {
        return view('livewire.projects.projects-table');
    }

    public function placeholder(array $params = [])
    {
        // When the card is scoped to one client we can cheaply COUNT its
        // projects (far cheaper than the paginated+search query the skeleton
        // stands in for) and skeleton the real number of rows — a client with
        // no projects gets the card chrome alone instead of 5 fake rows that
        // vanish a moment later.
        $clientId = $params['clientId'] ?? $params['client-id'] ?? null;

        // Unscoped index: the table is Meilisearch-backed but its default view
        // is just "latest status == the selected code", which a plain COUNT
        // reproduces far more cheaply than the search round trip — and without
        // it the skeleton was ~180px short of the real first page.
        $statusCode = $params['projectStatusTitle'] ?? $params['project-status-title'] ?? null;

        $rows = filled($clientId)
            ? min(Project::where('client_id', (int) $clientId)->count(), 5)
            : min(
                Project::query()
                    ->when(filled($statusCode), fn ($q) => $q->whereHas(
                        'latestStatus',
                        fn ($s) => $s->where('status_code', (int) $statusCode)
                    ))
                    ->count(),
                static::placeholderRows()
            );

        return view('livewire.projects.projects-table-placeholder', [
            'tableView' => $params['view'] ?? null,
            'placeholderRows' => $rows,
        ]);
    }
}

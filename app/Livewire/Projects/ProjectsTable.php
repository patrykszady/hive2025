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

        if ($selectedClientId !== null) {
            $query = Project::query()
                ->with(['statuses', 'client.users', 'createdByVendor']);

            // If the selected client is the current vendor's own client record
            // (vendor_id on the client matches the logged-in vendor), only show
            // projects linked via project_vendor for this vendor + client
            $selectedClient = \App\Models\Client::find($selectedClientId);
            $currentVendorId = auth()->user()->vendor?->id;

            if ($selectedClient && $currentVendorId && (int) $selectedClient->vendor_id === $currentVendorId) {
                $query->whereHas('vendors', function ($vendorQuery) use ($selectedClientId, $currentVendorId) {
                    $vendorQuery->where('project_vendor.client_id', $selectedClientId)
                        ->where('project_vendor.vendor_id', $currentVendorId);
                });
            } else {
                $query->where(function ($builder) use ($selectedClientId) {
                    $builder->where('client_id', $selectedClientId)
                        ->orWhereHas('vendors', function ($vendorQuery) use ($selectedClientId) {
                            $vendorQuery->where('project_vendor.client_id', $selectedClientId);
                        });
                });
            }

            if ($projectSearch !== '') {
                $query->where(function ($builder) use ($projectSearch) {
                    $builder->where('project_name', 'like', "%{$projectSearch}%")
                        ->orWhere('address', 'like', "%{$projectSearch}%");
                });
            }

            $statusCodes = $this->projectStatusTitle !== null && $this->projectStatusTitle !== '' ? [$this->projectStatusTitle] : [];
            $validCodes = [1, 2, 3, 4, 5, 6, 7, 8, 10, 11];

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

                if (! empty($codes)) {
                    $currentVendorId = $currentVendorId ?? auth()->user()->vendor?->id;
                    $query->whereHas('statuses', function ($statusQuery) use ($codes, $currentVendorId) {
                        $statusQuery->where('belongs_to_vendor_id', $currentVendorId)
                            ->whereIn('status_code', $codes)
                            ->whereColumn('id', DB::raw(
                                '(SELECT ps2.id FROM project_status ps2 WHERE ps2.project_id = project_status.project_id AND ps2.belongs_to_vendor_id = ' . (int) $currentVendorId . ' ORDER BY ps2.start_date DESC, ps2.id DESC LIMIT 1)'
                            ));
                    });
                }
            }

            return $query
                ->orderByDesc(ProjectStatus::query()
                    ->select('start_date')
                    ->whereColumn('project_id', 'projects.id')
                    ->orderByDesc('start_date')
                    ->orderByDesc('id')
                    ->limit(1))
                ->paginate(20, pageName: $this->getPageName());
        }

        $filters = [];

        if (! empty($clientIds)) {
            $filters[] = 'client_id IN ['.implode(',', array_map('intval', $clientIds)).']';
        }

        $statusCodes = $this->projectStatusTitle !== null && $this->projectStatusTitle !== '' ? [$this->projectStatusTitle] : [];
        $validCodes = [1, 2, 3, 4, 5, 6, 7, 8, 10, 11];

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

        if (! empty($codes)) {
            $vendorId = (int) auth()->user()->vendor->id;
            $vendorStatusFilters = collect($codes)
                ->map(fn($code) => '"' . $vendorId . '_' . $code . '"')
                ->implode(', ');
            $filters[] = 'vendor_status_codes IN [' . $vendorStatusFilters . ']';
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

        $validCodes = [1, 2, 3, 4, 5, 6, 7, 8, 10, 11];
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

    public function placeholder()
    {
        return view('livewire.projects.projects-table-placeholder');
    }
}

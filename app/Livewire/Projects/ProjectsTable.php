<?php

namespace App\Livewire\Projects;

use App\Models\Project;
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
    public $projectStatusTitle = [];
    #[Reactive]
    public $view = null;

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

        if ($this->clientId !== null && $this->clientId !== '') {
            $clientIds = [(int) $this->clientId];
        } elseif (! empty($this->clientVendorId)) {
            $clientIds = Project::where('belongs_to_vendor_id', (int) $this->clientVendorId)
                ->pluck('client_id')
                ->toArray();
        }

        if (auth()->user()->is_client_user) {
            $userClientIds = auth()->user()->clients()->pluck('clients.id')->toArray();
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

        if (! empty($clientIds)) {
            $filters[] = 'client_id IN ['.implode(',', array_map('intval', $clientIds)).']';
        }

        $statusCodes = is_array($this->projectStatusTitle) ? $this->projectStatusTitle : [$this->projectStatusTitle];
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
                $filters[] = 'latest_status_code IN ['.implode(',', $codes).']';
            }
        }

        return Project::scopedSearch($projectSearch, $filters, 'latest_status_date', 'desc')
            ->query(function ($query) {
                $query->with(['latestStatus', 'client.users', 'createdByVendor']);
            })
            ->paginate(20, pageName: $this->getPageName());
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

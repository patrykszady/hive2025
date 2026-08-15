<?php

namespace App\Livewire\Projects;

use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectStatus;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

class ProjectsIndex extends Component
{
    use AuthorizesRequests, WithPagination;

    public $project_name_search = '';
    public $client_id = null;

    public $client = null;

    // Store selected status code from filter (as int); null = all
    public $project_status_title = 6;

    protected function queryString(): array
    {
        $params = [
            'project_name_search' => ['except' => ''],
            'client_id' => ['except' => null],
        ];

        if (! $this->view) {
            $params['project_status_title'] = ['except' => null];
        }

        return $params;
    }

    public $view = null;
    public $skipProjectSearchReset = false;
    public $skipClientReset = false;

    public function mount()
    {
        if (request()->has('project_name_search')) {
            $this->project_name_search = (string) request()->query('project_name_search');
        }

        if (request()->has('client_id')) {
            $clientId = request()->query('client_id');
            $this->client_id = $clientId !== '' && $clientId !== null ? (int) $clientId : null;
        }

        if (request()->has('project_status_title')) {
            $statusParam = request()->query('project_status_title');
            $this->project_status_title = $statusParam !== '' && $statusParam !== null ? (int) $statusParam : null;
        }

        $this->skipProjectSearchReset = request()->filled('project_name_search');
        $this->skipClientReset = request()->filled('client_id');
        $hasFilterParams = request()->filled('client_id') || request()->filled('project_name_search');
        $hasStatusParam = request()->has('project_status_title');

        if ($this->client) {
            $this->client_id = $this->client->id;
        }

        // Special case for view mode
        if ($this->view == true) {
            $this->project_status_title = null;
            return;
        }

        // Check URL parameters first
        if ($hasStatusParam) {
            $validCodes = [1, 2, 3, 4, 5, 6, 7, 8, 10, 11];
            $code = (int) $this->project_status_title;
            $this->project_status_title = in_array($code, $validCodes) ? $code : null;
            Session::put('projects.status', $this->project_status_title);
        } elseif (auth()->user()->is_browsing_as_client) {
            $this->project_status_title = null;
        } else {
            $hasActiveProjects = ProjectStatus::where('status_code', 6)
                ->whereHas('project')
                ->exists();

            $this->project_status_title = $hasActiveProjects ? 6 : null;
        }

        if ($hasFilterParams && ! $hasStatusParam) {
            $this->project_status_title = null;
            Session::put('projects.status', null);
        }
    }


    public function updating($field)
    {
        $this->resetPage();
    }

    public function updated($field)
    {
        if ($field === 'project_status_title') {
            if ($this->project_status_title === '') {
                $this->project_status_title = null;
            }
            Session::put('projects.status', $this->project_status_title);
        }
        
        // Reset filters logic
        if ($field === 'client_id') {
            if ($this->client_id === '') {
                $this->client_id = null;
            }

            if ($this->skipClientReset) {
                $this->skipClientReset = false;
                return;
            }

            return;
        }

        if ($field === 'project_name_search') {
            if ($this->skipProjectSearchReset) {
                $this->skipProjectSearchReset = false;
                return;
            }
        }
    }


    #[Computed]
    public function clients()
    {
        if (auth()->user()->is_browsing_as_client) {
            return auth()->user()->clients()->get();
        }

        return Client::cachedDropdownList();
    }

    #[Title('Projects')]
    public function render()
    {
        $this->authorize('viewAny', Project::class);
        return view('livewire.projects.index', [
            'clients' => $this->clients,
        ]);
    }
}
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
    public $clients = [];
    public $client_id = '';

    public $client = null;

    public $project_status_title = 'Active';

    public $view = null;

    protected $queryString = [
        'project_name_search' => ['except' => ''],
        'client_id' => ['except' => ''],
        'project_status_title' => ['except' => ''],
    ];

    public function mount()
    {
        if ($this->client) {
            $this->client_id = $this->client->id;
        } else {
            $this->clients = Client::orderBy('created_at', 'DESC')->get();
        }

        // Special case for view mode
        if ($this->view == true) {
            $this->project_status_title = null;
            return;
        }
        
        // Check URL parameters first
        if (request()->has('project_status_title')) {
            // If URL parameter exists, store it in session
            Session::put('projects.status', $this->project_status_title);
        }
        // No URL parameter, but we have session value
        elseif (($sessionStatus = Session::get('projects.status')) && $sessionStatus !== 'Active') {
            // Use session value and update property
            $this->project_status_title = $sessionStatus;
        }
    }

    public function updating($field)
    {
        $this->resetPage();
    }

    public function updated($field)
    {
        if ($field === 'project_status_title') {
            // Always update session when status changes
            Session::put('projects.status', $this->project_status_title);
        }
        
        // Reset filters logic
        if ($field === 'client_id') {
            $this->project_status_title = '';
            Session::put('projects.status', '');
        }

        if ($field === 'project_name_search') {
            $this->project_status_title = '';
            $this->client_id = '';
            Session::put('projects.status', '');
        }
    }

    #[Computed]
    public function projects()
    {
        // Existing projects method unchanged
        if (! is_null($this->client)) {
            if (isset($this->client->vendor_id)) {
                //all clients(projects) with $client->vendor_id
                $client_ids = Project::where('belongs_to_vendor_id', $this->client->vendor_id)->pluck('client_id')->toArray();
            } else {
                $client_ids = [$this->client->id];
            }
        } else {
            $client_ids = [];
        }

        return Project::with('latestStatus')
            ->when($this->project_status_title !== null && $this->project_status_title !== 'ALL', function ($query) {
                $query->whereHas('latestStatus', function ($query) {
                    $query->whereIn('title', $this->project_status_title === 'Complete'
                        ? ['Complete', 'Service Call Complete', 'Service Call']
                        : [$this->project_status_title]);
                });
            })
            ->when($this->client !== null, function ($query) use ($client_ids) {
                $query->whereIn('client_id', $client_ids);
            })
            ->orderBy(
                ProjectStatus::select('start_date')
                    ->whereColumn('project_id', 'projects.id')
                    ->latest('start_date')
                    ->take(1),
                'desc'
            )
            ->paginate(20);
    }

    #[Title('Projects')]
    public function render()
    {
        $this->authorize('viewAny', Project::class);
        return view('livewire.projects.index');
    }
}
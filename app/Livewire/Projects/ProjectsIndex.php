<?php

namespace App\Livewire\Projects;

use App\Models\Client;
use App\Models\Project;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

// #[Lazy]
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
        }else{
            $this->clients = Client::orderBy('created_at', 'DESC')->get();
        }

        if ($this->view == true) {
            $this->project_status_title = null;
        }
    }

    public function updating($field)
    {
        $this->resetPage();
    }

    public function updated($field)
    {
        if ($field === 'client_id') {
            $this->project_status_title = '';
        }

        if ($field === 'project_name_search') {
            $this->project_status_title = '';
            $this->client_id = '';
        }
    }

    #[Computed]
    public function projects()
    {
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

        return Project::with('latestStatus') // Eager load the latest status for each project
            ->orderBy('created_at', 'DESC') // Order projects by their created_at date
            // ->where('address', 'like', "%{$this->project_name_search}%") // Filter by address if provided
            ->when($this->project_status_title !== null && $this->project_status_title !== 'ALL', function ($query) {
                $query->whereHas('latestStatus', function ($query) {
                    $query->whereIn('title', $this->project_status_title === 'Complete'
                        ? ['Complete', 'Service Call Complete', 'Service Call'] // Include additional statuses for "Complete"
                        : [$this->project_status_title]); // Filter by other status titles
                });
            })
            ->when($this->client !== null, function ($query) use ($client_ids) {
                $query->whereIn('client_id', $client_ids); // Filter by client IDs
            })
            ->paginate(20); // Paginate the results
    }

    #[Title('Projects')]
    public function render()
    {
        $this->authorize('viewAny', Project::class);
        return view('livewire.projects.index');
    }
}

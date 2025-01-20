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

        return Project::orderBy('created_at', 'DESC')
            // ->withWhereHas('vendors', function ($query) {
            //     $query->where('vendor_id', auth()->user()->vendor->id);
            // })
            ->where('address', 'like', "%{$this->project_name_search}%")
            ->when($this->project_status_title != null && $this->project_status_title != 'ALL', function ($query) {
                return $query->status($this->project_status_title == 'Complete' ? [$this->project_status_title, 'Service Call Complete'] : $this->project_status_title)->sortByDesc('last_status.start_date');
            })
            ->when($this->client != null, function ($query) use ($client_ids) {
                return $query->whereIn('client_id', $client_ids);
            })
            // ->when($this->client_id != NULL, function ($query) {
            //     return $query->where('client_id', $this->client_id);
            // })
            ->paginate(10);
    }

    #[Title('Projects')]
    public function render()
    {
        $this->authorize('viewAny', Project::class);

        $clients = Client::orderBy('created_at', 'DESC')->get();

        return view('livewire.projects.index', [
            'clients' => $clients,
        ]);
    }
}

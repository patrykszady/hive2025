<?php

namespace App\Livewire\Projects;

use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectStatus;
use Carbon\Carbon;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
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
        } else {
            // Client users only see their own clients
            if (auth()->user()->is_browsing_as_client) {
                $this->clients = auth()->user()->clients()->get();
            } else {
                $this->clients = Client::orderBy('created_at', 'DESC')->get();
            }
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
    public function stats()
    {
        // Get base query
        $baseQuery = Project::query();
        
        if (! is_null($this->client)) {
            $currentVendorId = auth()->user()->vendor?->id;

            // If viewing the current vendor's own client record, scope to project_vendor
            if ($currentVendorId && isset($this->client->vendor_id) && (int) $this->client->vendor_id === $currentVendorId) {
                $projectIds = \Illuminate\Support\Facades\DB::table('project_vendor')
                    ->where('vendor_id', $currentVendorId)
                    ->where('client_id', $this->client->id)
                    ->pluck('project_id');
                $baseQuery->whereIn('id', $projectIds);
            } elseif (isset($this->client->vendor_id)) {
                $client_ids = Project::where('belongs_to_vendor_id', $this->client->vendor_id)->pluck('client_id')->toArray();
                $baseQuery->whereIn('client_id', $client_ids);
            } else {
                $client_ids = [$this->client->id];
                $baseQuery->whereIn('client_id', $client_ids);
            }
        } elseif (auth()->user()->is_browsing_as_client) {
            // Client users only see stats for their client's projects
            $userClientIds = auth()->user()->clients()->pluck('clients.id')->toArray();
            $baseQuery->whereIn('client_id', $userClientIds);
        }

        $projectIds = (clone $baseQuery)->pluck('id');

        $projects = (clone $baseQuery)->with('latestStatus')->get();

        $latestStatuses = $projects
            ->pluck('latestStatus.title') // accessor provides label
            ->filter()
            ->countBy();

        $projectStatuses = $projectIds->isEmpty()
            ? collect()
            : ProjectStatus::select('project_id', 'status_code', 'start_date', 'id')
                ->whereIn('project_id', $projectIds)
                ->orderBy('project_id')
                ->orderBy('start_date')
                ->orderBy('id')
                ->get()
                ->groupBy('project_id')
                ->map(function ($statuses) {
                    return $statuses
                        ->values()
                        ->map(function ($status) {
                            return [
                                'title' => ProjectStatus::getLabelForCode((int) $status->status_code),
                                'start_date' => $status->start_date
                                    ? $status->start_date->copy()
                                    : null,
                            ];
                        });
                });

        // Define stats in display order
        $stats = [
            [
                'title' => 'Active',
                'value' => (string) $latestStatuses->get('Active', 0),
                'chartData' => $this->getYtdChartData('Active', $projectStatuses),
            ],
            [
                'title' => 'Estimate',
                'value' => (string) $latestStatuses->get('Estimate', 0),
                'chartData' => $this->getYtdChartData('Estimate', $projectStatuses),
            ],
            [
                'title' => 'Response',
                'value' => (string) $latestStatuses->get('Awaiting Response', 0),
                'chartData' => $this->getYtdChartData('Awaiting Response', $projectStatuses),
            ],
            [
                'title' => 'Scheduled',
                'value' => (string) $latestStatuses->get('Scheduled', 0),
                'chartData' => $this->getYtdChartData('Scheduled', $projectStatuses),
            ],
        ];

        return $stats;
    }

    protected function getYtdChartData(string $status, Collection $projectStatuses): array
    {
        $now = now();
        $currentYear = $now->year;
        $currentMonth = $now->month;

        if ($projectStatuses->isEmpty()) {
            return array_fill(0, $currentMonth, 0);
        }

        $monthlyData = [];
        $pointers = [];

        for ($month = 1; $month <= $currentMonth; $month++) {
            $endOfMonth = $now->copy()->setDate($currentYear, $month, 1)->endOfMonth();
            $count = 0;

            foreach ($projectStatuses as $projectId => $statuses) {
                $index = $pointers[$projectId] ?? -1;
                $statusesCount = $statuses->count();

                while (($index + 1) < $statusesCount) {
                    $next = $statuses[$index + 1];
                    /** @var Carbon|null $startDate */
                    $startDate = $next['start_date'];

                    if ($startDate !== null && $startDate->gt($endOfMonth)) {
                        break;
                    }

                    $index++;
                }

                $pointers[$projectId] = $index;

                if ($index >= 0) {
                    $currentStatus = $statuses[$index]['title'];
                    if ($currentStatus === $status) {
                        $count++;
                    }
                }
            }

            $monthlyData[] = $count;
        }

        return $monthlyData;
    }


    #[Title('Projects')]
    public function render()
    {
        $this->authorize('viewAny', Project::class);
        return view('livewire.projects.index');
    }
}
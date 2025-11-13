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
    public $client_id = '';

    public $client = null;

    // Store selected status codes from filter (as int); default to Active (6)
    public $project_status_title = [6];

    public $view = null;

    protected $queryString = [
        'project_name_search' => ['except' => ''],
        'client_id' => ['except' => ''],
        'project_status_title' => ['except' => [6]],
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
            $this->project_status_title = [];
            return;
        }
        
        // Check URL parameters first
        if (request()->has('project_status_title')) {
            // Ensure it's an array
            if (!is_array($this->project_status_title)) {
                $this->project_status_title = [$this->project_status_title];
            }
            // Cast to int codes and filter out invalid codes (0, 9)
            $validCodes = [1, 2, 3, 4, 5, 6, 7, 8, 10, 11];
            $this->project_status_title = array_values(
                array_filter(
                    array_map('intval', $this->project_status_title),
                    fn($code) => in_array($code, $validCodes)
                )
            );
            // If URL parameter exists, store it in session
            Session::put('projects.status', $this->project_status_title);
        }
        // No URL parameter, but we have session value
        elseif (($sessionStatus = Session::get('projects.status')) && $sessionStatus !== [6]) {
            // Use session value, cast to int, and filter invalid codes
            $validCodes = [1, 2, 3, 4, 5, 6, 7, 8, 10, 11];
            $sessionStatus = is_array($sessionStatus) ? $sessionStatus : [$sessionStatus];
            $this->project_status_title = array_values(
                array_filter(
                    array_map('intval', $sessionStatus),
                    fn($code) => in_array($code, $validCodes)
                )
            );
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
            $this->project_status_title = [];
            Session::put('projects.status', []);
        }

        if ($field === 'project_name_search') {
            $this->project_status_title = [];
            $this->client_id = '';
            Session::put('projects.status', []);
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
            ->when(!empty($this->project_status_title), function ($query) {
                // Expand "Complete" (7) to also include "Service Call" (8) for backwards compatibility
                $codes = collect($this->project_status_title)
                    ->flatMap(function ($code) {
                        if ($code === 7) {
                            return [7, 8]; // Complete, Service Call
                        }
                        return [(int)$code];
                    })
                    ->unique()
                    ->values()
                    ->all();
                    
                $query->whereHas('latestStatus', function ($query) use ($codes) {
                    $query->whereIn('status_code', $codes);
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

    #[Computed]
    public function stats()
    {
        // Get base query
        $baseQuery = Project::query();
        
        if (! is_null($this->client)) {
            if (isset($this->client->vendor_id)) {
                $client_ids = Project::where('belongs_to_vendor_id', $this->client->vendor_id)->pluck('client_id')->toArray();
            } else {
                $client_ids = [$this->client->id];
            }
            $baseQuery->whereIn('client_id', $client_ids);
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
<?php

namespace App\Livewire\Tasks;

use App\Models\Project;
use Carbon\Carbon;
use Carbon\CarbonInterval;
use Livewire\Attributes\Title;
use Livewire\Component;

class Planner extends Component
{
    public $single_project_id = null;

    public $days = [];

    public $projects = [];

    public $tasks = [];

    public $week = '';

    protected $queryString = [
        'week' => ['except' => ''],
    ];

    public function mount()
    {
        if ($this->week) {
            //5-24-2026 must be Y-m-d format, else go to else below
            $monday = $this->week;
        } else {
            $monday = today()->format('Y-m-d');
        }

        $this->set_week_days($monday);

        //tasks where between week
        $this->projects =
            Project::when(! is_null($this->single_project_id), function ($query, $item) {
                return $query->where('id', $this->single_project_id);
            })
                ->with(['tasks' => function ($query) {
                    $query->whereBetween('start_date', [$this->days[0]['database_date'], $this->days[6]['database_date']])->orWhereBetween('end_date', [$this->days[0]['database_date'], $this->days[6]['database_date']]);
                }])
                ->status(['Active', 'Scheduled', 'Service Call', 'Invited'])
                ->sortBy([['last_status.title', 'asc'], ['last_status.start_date', 'desc']])
                ->values();
    }

    public function set_week_days($monday)
    {
        $days = new \DatePeriod(
            Carbon::parse($monday)->startOfWeek(Carbon::MONDAY),
            CarbonInterval::day(),
            Carbon::parse($monday)->startOfWeek(Carbon::MONDAY)->endOfWeek(Carbon::SUNDAY)
        );

        $this->days = [];
        foreach ($days as $confirmed_date) {
            //need to account for saturday&sunday / days off
            $this->days[] = [
                'database_date' => $confirmed_date->format('Y-m-d'),
                'formatted_date' => $confirmed_date->format('D, m/d'),
                'is_today' => $confirmed_date == today(),
            ];
        }
    }

    public function weekToggle($direction)
    {
        $current_monday = $this->days[0]['database_date'];

        if ($direction == 'next') {
            $monday = Carbon::parse($current_monday)->addWeek()->format('Y-m-d');
        } elseif ($direction == 'previous') {
            $monday = Carbon::parse($current_monday)->subWeek()->format('Y-m-d');
        }

        $this->week = $monday;
        $this->mount();
        $this->dispatch('refresh_planner', $this->days)->to(PlannerProject::class);
    }

    #[Title('Planner')]
    public function render()
    {
        return view('livewire.tasks.planner');
    }
}

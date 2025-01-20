<?php

namespace App\Livewire\Tasks;

use App\Models\Project;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

class PlannerList extends Component
{
    public $week = '';

    protected $listeners = ['refreshComponent' => '$refresh'];

    protected $queryString = [
        'week' => ['except' => ''],
    ];

    public function set_week_days($monday)
    {
        $days = CarbonPeriod::create(
            Carbon::parse($monday)->startOfWeek(Carbon::MONDAY),
            '1 day',
            Carbon::parse($monday)->addWeek()->endOfWeek(Carbon::SUNDAY)
        );

        $days_formatted = [];
        foreach ($days as $confirmed_date) {
            //need to account for saturday&sunday / days off
            $days_formatted[] = [
                'database_date' => $confirmed_date->format('Y-m-d'),
                'formatted_date' => $confirmed_date->format('D, m/d'),
                'is_today' => $confirmed_date == today(),
                'is_weekend' => $confirmed_date->isWeekend(),
            ];
        }

        return $days_formatted;
    }

    #[Computed]
    public function days()
    {
        if ($this->week) {
            //5-24-2024 must be Y-m-d format, else go to else below
            $monday = $this->week;
        } else {
            $monday = today()->format('Y-m-d');
        }

        return $this->set_week_days($monday);
    }

    #[Computed]
    public function projects()
    {
        return Project::with('tasks')
            ->status(['Active', 'Scheduled', 'Service Call', 'Invited'])
            ->sortBy([['last_status.title', 'asc'], ['last_status.start_date', 'desc']]);
    }

    //render method not needed if view and component follow a convention
    #[Title('Planner')]
    public function render()
    {
        return view('livewire.tasks.planner-list');
    }
}

<?php

namespace App\Livewire\Dashboard;

use App\Models\Task;
use Carbon\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Component;

class UserTasks extends Component
{
    protected $listeners = ['refreshComponent' => '$refresh'];

    #[Computed]
    public function tasks()
    {
        $userId = (string) auth()->id();
        $today = Carbon::today();

        return Task::whereJsonContains('user_ids', $userId)
            ->where(function ($query) use ($today) {
                // Tasks that are today or in the future
                $query->whereDate('end_date', '>=', $today);
            })
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->with(['project.client'])
            ->orderBy('start_date')
            ->orderBy('end_date')
            ->limit(10)
            ->get();
    }

    public function render()
    {
        return view('livewire.dashboard.user-tasks');
    }
}

<?php

namespace App\Livewire\Timesheets;

use App\Models\Hour;
use App\Models\Timesheet;
use Carbon\Carbon;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Lazy]
class TimesheetsIndex extends Component
{
    use AuthorizesRequests, WithPagination;

    #[Url(except: '')]
    public $amount = '';

    #[Title('Timesheets')]
    public function render()
    {
        $this->authorize('viewAny', Timesheet::class);

        $weekly_hours_to_confirm =
            Hour::orderBy('date', 'DESC')
                // ->where('user_id', auth()->user()->id)
                ->whereNull('timesheet_id')
                ->get()
                ->groupBy(function ($item) {
                    return $item->user->first_name;
                })->toBase()
                ->transform(function ($item, $k) {
                    return $item->groupBy(function ($item) {
                        return Carbon::parse($item->date)->startOfWeek()->toFormattedDateString();
                    })->each(function ($group) {
                        // $group->sum_amount = $group->sum('amount');
                        $group->timesheet_id = $group->first()->id;
                        $group->sum_hours = $group->sum('hours');
                    });
                });

        $timesheets =
            Timesheet::orderBy('date', 'DESC')
                ->with('user')
                // ->where('user_id', auth()->user()->id)
                // ->withCount('hours')
                ->get()
                ->groupBy(function ($item) {
                    return $item->date->format('m/d/Y');
                })
                ->transform(function ($item, $k) {
                    return $item->groupBy(function ($item) {
                        return $item->user->first_name;
                    })->each(function ($group) {
                        $group->timesheet_id = $group->first()->id;
                        $group->date = $group->first()->date->format('m/d/Y');
                        $group->sum_amount = $group->sum('amount');
                        $group->sum_hours = $group->sum('hours');
                    });
                })->paginate(8);

        return view('livewire.timesheets.index', [
            'weekly_hours_to_confirm' => $weekly_hours_to_confirm,
            'timesheets' => $timesheets,
        ]);
    }
}

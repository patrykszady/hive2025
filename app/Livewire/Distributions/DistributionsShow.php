<?php

namespace App\Livewire\Distributions;

use App\Models\Distribution;
use App\Models\Vendor;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use Livewire\WithPagination;

class DistributionsShow extends Component
{
    use AuthorizesRequests, WithPagination;

    public Distribution $distribution;

    public $year = null;

    public $date = [];

    public function mount()
    {
        //if $this->year = NULL = YTD
        if (is_null($this->year)) {
            $this->date['start'] = today()->subYear(1)->format('Y-m-d');
            $this->date['end'] = today()->format('Y-m-d');
        } else {
            dd('year isset/not null');
        }
    }

    public function render()
    {
        $this->authorize('view', $this->distribution);
        // dd($this->distribution->expenses->sum('amount') + $this->distribution->splits->sum('amount'));
        //group expenses by vendor where this Distribution, then sum those vendor expenses
        //each group/vendor sum all expenses
        $distribution_expenses_vendors =
            $this->distribution->expenses()->with(['vendor'])
                ->whereBetween('date', [$this->date['start'], $this->date['end']])
                ->get();

        $distribution_splits_vendors =
            $this->distribution->splits()->with('expense')
                ->whereHas('expense', function ($query) {
                    return $query->whereBetween('expenses.date', [$this->date['start'], $this->date['end']]);
                })

                ->get();

        $distribution_vendors =
            $distribution_expenses_vendors
                ->merge($distribution_splits_vendors)
                ->groupBy('vendor_id');

        $distribution_get_vendors = Vendor::whereIn('id', $distribution_vendors->keys())->get();

        $distribution_vendors =
            $distribution_expenses_vendors->merge($distribution_splits_vendors)
                ->groupBy('vendor_id')
                ->each(function ($item, $key) use ($distribution_get_vendors) {
                    $item->sum = $item->sum('amount');
                    $item->vendor = $distribution_get_vendors->where('id', $key)->first();
                })
                ->sortByDesc('sum');

        // dd($distribution_vendors[46]);
        $distribution_sum = 0;
        foreach ($distribution_expenses_vendors as $distribution_vendor) {
            $distribution_sum += $distribution_vendor->sum;
            $distribution_expenses_vendors->vendors_sum = $distribution_sum;
        }

        $distribution_projects =
            $this->distribution->projects()
                ->orderBy('created_at', 'DESC')
                ->paginate(10);

        //sum where DATE/YEAR between dates
        //->where('projects.id', 180)->first()->statuses()->where('title', 'Complete')->first()->whereBetween('start_date', [$this->date['start'], $this->date['end']])->first();
        $this->distribution->earned =
            $this->distribution->projects()->whereHas('statuses', function ($query) {
                //where first Complete is within dates
                $query->where('title', 'Complete')->whereBetween('start_date', [$this->date['start'], $this->date['end']]);
                // $query->where('title', 'Complete')->whereBetween('start_date', [$this->date['start'], $this->date['end']]);
            })->sum('amount');

        // dd($this->distribution->earned);
        // $this->distribution->projects()->whereBetween('distribution_project.created_at', [$this->date['start'], $this->date['end']])->sum('amount');

        $this->distribution->paid = $distribution_vendors->sum('sum');

        return view('livewire.distributions.show', [
            'distribution_vendors' => $distribution_vendors,
            'distribution_projects' => $distribution_projects,
        ]);
    }
}

<?php

namespace App\Livewire\Distributions;

use App\Models\Distribution;
use Livewire\Component;

use Livewire\Attributes\Computed;

class DistributionsList extends Component
{
    /**
     * Column defs for the distributions table — the loading skeleton renders
     * from the same array as the real header row, so widths can't drift.
     *
     * @return array<int, array{label: string, width: string, skeleton?: string, skeletonWidth?: string}>
     */
    public static function columnDefs(): array
    {
        return [
            ['label' => 'Distribution', 'width' => 'w-[32%] min-w-0', 'skeletonWidth' => 'w-32'],
            ['label' => 'Balance', 'width' => 'text-right w-[17%]', 'skeletonWidth' => 'w-16'],
            ['label' => 'YTD Earned', 'width' => 'text-right w-[17%]', 'skeletonWidth' => 'w-16'],
            ['label' => 'YTD Paid', 'width' => 'text-right w-[17%]', 'skeletonWidth' => 'w-16'],
            ['label' => 'YTD Balance', 'width' => 'text-right w-[17%]', 'skeletonWidth' => 'w-16'],
        ];
    }

    protected $listeners = ['refreshComponent' => '$refresh'];

    public $view = false;

    #[Computed]
    public function distributions()
    {
        $ytdStart = today()->subYear();

        return Distribution::query()
            // All-time totals
            ->withSum('projects', 'distribution_project.amount')
            ->withSum(['expenses as expenses_total' => function ($q) {
                $q->whereNull('deleted_at');
            }], 'amount')
            ->withSum(['splits as splits_total' => function ($q) {
                $q->whereNull('deleted_at');
            }], 'amount')
            // YTD totals (rolling 12 months)
            ->withSum(['projects as ytd_earned' => function ($q) use ($ytdStart) {
                $q->whereHas('statuses', function ($sq) use ($ytdStart) {
                    $sq->where('status_code', 7)
                        ->where('start_date', '>=', $ytdStart);
                });
            }], 'distribution_project.amount')
            ->withSum(['expenses as ytd_expenses' => function ($q) use ($ytdStart) {
                $q->whereNull('deleted_at')
                    ->where('date', '>=', $ytdStart);
            }], 'amount')
            ->withSum(['splits as ytd_splits' => function ($q) use ($ytdStart) {
                $q->whereNull('deleted_at')
                    ->whereHas('expense', function ($eq) use ($ytdStart) {
                        $eq->where('date', '>=', $ytdStart);
                    });
            }], 'amount')
            ->get()
            ->map(function ($distribution) {
                $distribution->earned = $distribution->projects_sum_distribution_projectamount ?? 0;
                $distribution->paid = ($distribution->expenses_total ?? 0) + ($distribution->splits_total ?? 0);
                $distribution->balance = $distribution->earned - $distribution->paid;
                $distribution->ytd_paid = ($distribution->ytd_expenses ?? 0) + ($distribution->ytd_splits ?? 0);
                $distribution->ytd_balance = ($distribution->ytd_earned ?? 0) - $distribution->ytd_paid;

                return $distribution;
            });
    }

    public function render()
    {
        $this->authorize('viewAny', Distribution::class);

        return view('livewire.distributions.list');
    }
}

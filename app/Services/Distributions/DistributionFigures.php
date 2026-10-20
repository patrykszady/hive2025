<?php

namespace App\Services\Distributions;

use App\Models\Distribution;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * The figures behind App\Livewire\Distributions\{DistributionsList,
 * DistributionsShow,DistributionCreate} — shared with the app API's
 * DistributionController so both read the exact same numbers.
 */
class DistributionFigures
{
    /**
     * All-time and YTD (rolling 12 months) balance per distribution — the
     * /distributions table. Each row gains earned/paid/balance/ytd_paid/
     * ytd_balance, same as DistributionsList::distributions().
     *
     * @return Collection<int, Distribution>
     */
    public function ytdTable(): Collection
    {
        $ytdStart = today()->subYear();

        return Distribution::query()
            ->withSum('projects', 'distribution_project.amount')
            ->withSum(['expenses as expenses_total' => function ($q) {
                $q->whereNull('deleted_at');
            }], 'amount')
            ->withSum(['splits as splits_total' => function ($q) {
                $q->whereNull('deleted_at');
            }], 'amount')
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
            ->map(function (Distribution $distribution) {
                $distribution->earned = $distribution->projects_sum_distribution_projectamount ?? 0;
                $distribution->paid = ($distribution->expenses_total ?? 0) + ($distribution->splits_total ?? 0);
                $distribution->balance = $distribution->earned - $distribution->paid;
                $distribution->ytd_paid = ($distribution->ytd_expenses ?? 0) + ($distribution->ytd_splits ?? 0);
                $distribution->ytd_balance = ($distribution->ytd_earned ?? 0) - $distribution->ytd_paid;

                return $distribution;
            });
    }

    /**
     * The vendors paid by this distribution within a date range, and its
     * projects — same shape as DistributionsShow::render(). Defaults to the
     * trailing year (DistributionsShow's only supported range: the web
     * doesn't yet offer picking a calendar year either).
     *
     * @return array{vendors: Collection, earned: float, paid: float, projects: LengthAwarePaginator}
     */
    public function detail(Distribution $distribution, string $start, string $end, ?int $projectsPage = null, int $perPage = 10): array
    {
        $expenseVendors = $distribution->expenses()->with(['vendor'])
            ->whereBetween('date', [$start, $end])
            ->get();

        $splitVendors = $distribution->splits()->with('expense')
            ->whereHas('expense', fn ($query) => $query->whereBetween('expenses.date', [$start, $end]))
            ->get();

        $byVendorId = $expenseVendors->merge($splitVendors)->groupBy('vendor_id');

        $vendors = Vendor::whereIn('id', $byVendorId->keys())->get();

        $breakdown = $byVendorId
            ->each(function (EloquentCollection $items, int $vendorId) use ($vendors) {
                $items->sum = $items->sum('amount');
                $items->vendor = $vendors->firstWhere('id', $vendorId);
            })
            ->sortByDesc('sum');

        $earned = (float) $distribution->projects()
            ->whereHas('statuses', fn ($query) => $query->where('status_code', 7)->whereBetween('start_date', [$start, $end]))
            ->sum('amount');

        // A null $projectsPage falls back to Laravel's normal page
        // resolver — Livewire's WithPagination binds that for
        // DistributionsShow; the app API passes its own page number
        // explicitly instead.
        $projects = $distribution->projects()
            ->orderBy('created_at', 'DESC')
            ->paginate($perPage, ['*'], 'page', $projectsPage);

        return [
            'vendors' => $breakdown,
            'earned' => $earned,
            'paid' => (float) $breakdown->sum('sum'),
            'projects' => $projects,
        ];
    }

    /**
     * Employed, role_id 1 (Admin-tier) team members who don't yet have a
     * distribution — the candidates DistributionCreate::mount() offers.
     *
     * @return EloquentCollection<int, User>
     */
    public function eligibleUsers(Vendor $vendor): EloquentCollection
    {
        return $vendor->users()->whereDoesntHave('distributions')->employed()->wherePivot('role_id', 1)->get();
    }

    public function create(Vendor $vendor, int $userId, string $name): Distribution
    {
        return Distribution::create([
            'vendor_id' => $vendor->id,
            'name' => $name,
            'user_id' => $userId,
        ]);
    }
}

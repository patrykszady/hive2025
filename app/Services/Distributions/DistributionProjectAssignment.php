<?php

namespace App\Services\Distributions;

use App\Models\Distribution;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;

/**
 * Assigning a project's profit split across distributions — extracted from
 * App\Livewire\Distributions\{DistributionProjectsForm,DistributionProjectsTable}
 * so the Hive Contractors app's DistributionProjectAssignmentController
 * writes and lists identically. "Individual" assigns one project (reached
 * from either the Projects With or Projects Without Distributions table);
 * "bulk" assigns the same split to every completed project with none yet.
 */
class DistributionProjectAssignment
{
    /** The percent column must sum to exactly this across every distribution — mirrors both forms' rules(). */
    public const PERCENT_TOTAL = 100;

    /**
     * Completed projects ($type 'With' or 'Without' a distribution split),
     * newest-completed first — mirrors DistributionProjectsTable::render().
     */
    public function projectsQuery(string $type): Builder
    {
        return Project::with(['client.users', 'distributions', 'statuses' => function ($query) {
            $query->where('status_code', 7)->orderBy('start_date', 'asc');
        }])
            ->when($type === 'With', fn ($query) => $query->whereHas('distributions'))
            ->when($type === 'Without', fn ($query) => $query->whereDoesntHave('distributions'))
            ->whereHas('statuses', fn ($query) => $query->where('status_code', 7))
            ->orderBy(function ($query) {
                $query->select('start_date')
                    ->from('project_status')
                    ->whereColumn('project_status.project_id', 'projects.id')
                    ->where('status_code', 7)
                    ->orderBy('start_date', 'asc')
                    ->limit(1);
            }, 'desc');
    }

    /**
     * Every distribution, prefilled with $project's existing split if it
     * has one (percent/amount null otherwise) — mirrors
     * DistributionProjectsForm::resetModal()+addDis(). Pass null for the
     * bulk-assign form, where there is no existing split to prefill.
     *
     * @return list<array{id: int, name: string, percent: ?int, amount: ?float}>
     */
    public function rows(?Project $project): array
    {
        $existing = $project
            ? $project->distributions->mapWithKeys(fn (Distribution $distribution): array => [
                (int) $distribution->id => [
                    'percent' => (int) $distribution->pivot->percent,
                    'amount' => (float) $distribution->pivot->amount,
                ],
            ])
            : collect();

        return Distribution::query()
            ->orderBy('id')
            ->get(['id', 'name'])
            ->map(function (Distribution $distribution) use ($existing) {
                $row = $existing->get((int) $distribution->id);

                return [
                    'id' => (int) $distribution->id,
                    'name' => (string) $distribution->name,
                    'percent' => $row['percent'] ?? null,
                    'amount' => $row['amount'] ?? null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, int>  $percentsByDistributionId
     */
    public function percentSum(array $percentsByDistributionId): int
    {
        return (int) collect($percentsByDistributionId)
            ->filter(fn ($percent) => is_numeric($percent) && (int) $percent > 0)
            ->sum();
    }

    /**
     * Syncs $project's profit split — mirrors DistributionProjectsForm::store().
     *
     * @param  array<int, int>  $percentsByDistributionId
     */
    public function assign(Project $project, array $percentsByDistributionId): void
    {
        $profit = (float) data_get($project->finances ?? [], 'profit', 0);

        // Client-controlled distribution ids: only ever sync ids
        // DistributionScope says belong to my vendor.
        $validDistributionIds = Distribution::pluck('id')->all();

        $syncData = [];

        foreach ($percentsByDistributionId as $distributionId => $percent) {
            $percent = (int) $percent;

            if ($percent <= 0 || ! in_array((int) $distributionId, $validDistributionIds, true)) {
                continue;
            }

            $syncData[(int) $distributionId] = [
                'percent' => $percent,
                'amount' => round($profit * ($percent / 100), 2),
            ];
        }

        $project->distributions()->sync($syncData);
    }

    /**
     * Assigns the same split to every completed project with none yet —
     * mirrors DistributionProjectsForm::storeBulk(). Returns how many
     * projects were assigned.
     *
     * @param  array<int, int>  $percentsByDistributionId
     */
    public function assignBulk(array $percentsByDistributionId): int
    {
        $projects = Project::query()
            ->whereDoesntHave('distributions')
            ->whereHas('statuses', fn ($query) => $query->where('status_code', 7))
            ->get();

        $validDistributionIds = Distribution::pluck('id')->all();
        $count = 0;

        foreach ($projects as $project) {
            $profit = (float) data_get($project->finances ?? [], 'profit', 0);
            $syncData = [];

            foreach ($percentsByDistributionId as $distributionId => $percent) {
                $percent = (int) $percent;

                if ($percent <= 0 || ! in_array((int) $distributionId, $validDistributionIds, true)) {
                    continue;
                }

                $syncData[(int) $distributionId] = [
                    'percent' => $percent,
                    'amount' => round($profit * ($percent / 100), 2),
                ];
            }

            if (! empty($syncData)) {
                $project->distributions()->sync($syncData);
                $count++;
            }
        }

        return $count;
    }
}

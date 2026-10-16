<?php

namespace App\Services;

use App\Models\ProjectStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

class ProjectStatusTrends
{
    /** Weekly points in each sparkline before today's. */
    public const WEEKS = 12;

    /** The comparison: today against this many days ago. */
    public const COMPARE_DAYS = 30;

    /**
     * How many of a company's projects sit in each status: now, COMPARE_DAYS
     * ago, and at each of the last WEEKS weeks. A project's status on a day is
     * its latest status starting on or before that day. "Now" takes the latest
     * status outright, as the projects list's Status filter does, so a card's
     * number is what clicking it lists.
     *
     * Cached for a few minutes, keyed on the company's newest status row, so a
     * status change shows on the next render.
     *
     * @return array<int, array{now: int, before: int, points: list<int>}> keyed by status code
     */
    public function forVendor(int $vendorId, CarbonInterface $today): array
    {
        $today = CarbonImmutable::instance($today)->startOfDay();

        $fingerprint = ProjectStatus::withoutGlobalScopes()
            ->where('belongs_to_vendor_id', $vendorId)
            ->selectRaw('MAX(id) as newest, COUNT(*) as total')
            ->toBase()
            ->first();

        $key = sprintf('project-status-trends:%d:%s:%s:%s', $vendorId, $today->toDateString(), $fingerprint->newest ?? 0, $fingerprint->total ?? 0);

        return Cache::remember($key, now()->addMinutes(5), fn (): array => $this->compute($vendorId, $today));
    }

    /** @return array<int, array{now: int, before: int, points: list<int>}> */
    protected function compute(int $vendorId, CarbonImmutable $today): array
    {
        $rows = ProjectStatus::withoutGlobalScopes()
            ->join('projects', 'projects.id', '=', 'project_status.project_id')
            ->whereNull('projects.deleted_at')
            ->where('project_status.belongs_to_vendor_id', $vendorId)
            ->toBase()
            ->get(['project_status.id', 'project_status.project_id', 'project_status.status_code', 'project_status.start_date']);

        $weeklyDates = [];
        for ($weeksAgo = self::WEEKS; $weeksAgo >= 1; $weeksAgo--) {
            $weeklyDates[] = $today->subWeeks($weeksAgo)->toDateString();
        }
        $compareDate = $today->subDays(self::COMPARE_DAYS)->toDateString();

        $trends = [];
        $tally = function (?int $code, string $field, ?int $index = null) use (&$trends): void {
            if ($code === null) {
                return;
            }

            $trends[$code] ??= ['now' => 0, 'before' => 0, 'points' => array_fill(0, self::WEEKS + 1, 0)];

            if ($index === null) {
                $trends[$code][$field]++;
            } else {
                $trends[$code]['points'][$index]++;
            }
        };

        foreach ($rows->groupBy('project_id') as $statuses) {
            // Oldest first; a missing start date is the oldest, as the list's
            // "latest status" (start date desc) treats it.
            $timeline = $statuses
                ->map(fn ($row): array => [substr((string) $row->start_date, 0, 10), (int) $row->id, (int) $row->status_code])
                ->sort(fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]])
                ->values()
                ->all();

            $latest = end($timeline)[2];
            $tally($latest, 'now');
            $tally(self::statusOn($timeline, $compareDate), 'before');

            foreach ($weeklyDates as $index => $date) {
                $tally(self::statusOn($timeline, $date), 'points', $index);
            }

            // Today's point is the card's number.
            $tally($latest, 'points', self::WEEKS);
        }

        ksort($trends);

        return $trends;
    }

    /**
     * The status a project had on a day: its latest one starting by then.
     *
     * @param  list<array{0: string, 1: int, 2: int}>  $timeline  oldest first
     */
    protected static function statusOn(array $timeline, string $date): ?int
    {
        $code = null;

        foreach ($timeline as [$startDate, , $statusCode]) {
            if ($startDate > $date) {
                break;
            }

            $code = $statusCode;
        }

        return $code;
    }
}

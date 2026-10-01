<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Previously used task titles for the Create/Edit Task title autocomplete,
 * plus the vendor most often paired with a given title.
 *
 * Reused titles map to one vendor almost every time — "Plumbing" means
 * Accomplished J Plumbing on nearly every task that used it — so picking a
 * remembered title can fill the Vendor field too, saving the trip through a
 * 1000-option vendor directory for work this tenant does every week.
 */
class TaskTitleSuggestions
{
    /**
     * A title's remembered vendor only fills in when it was used on at
     * least this many of the title's tasks...
     */
    private const MIN_VENDOR_USES = 2;

    /**
     * ...and on at least this share of them. "Demo", split across several
     * vendors, must fill nothing.
     */
    private const MIN_VENDOR_SHARE = 0.6;

    /**
     * How long the per-vendor suggestion list is cached. Short enough that
     * a task created moments ago shows up without a deploy or a wait, long
     * enough to spare the modal a grouped query on every open.
     */
    private const CACHE_SECONDS = 60;

    /**
     * Suggestion strings for the title autocomplete, most-used first (ties
     * broken by most recent use), de-duplicated case-insensitively — keeping
     * whichever exact spelling was used most often.
     *
     * @return array<int, string>
     */
    public static function titlesForCurrentUser(): array
    {
        return self::rowsForCurrentUser()
            ->sort(fn (array $a, array $b) => $b['count'] <=> $a['count']
                ?: $b['last_used_at']->timestamp <=> $a['last_used_at']->timestamp)
            ->pluck('title')
            ->values()
            ->all();
    }

    /**
     * The vendor to fill in for $title, or null when the title is new, its
     * vendor is too split to call "the" vendor, or the remembered vendor
     * isn't one the caller may currently choose from (e.g. no longer in the
     * modal's vendor list).
     *
     * @param  array<int, int>  $selectableVendorIds  vendor ids the modal may fill in
     */
    public static function dominantVendorId(string $title, array $selectableVendorIds): ?int
    {
        $row = self::rowForTitle($title);

        if (! $row || $row['vendor_id'] === null) {
            return null;
        }

        if ($row['vendor_count'] < self::MIN_VENDOR_USES) {
            return null;
        }

        if (($row['vendor_count'] / $row['count']) < self::MIN_VENDOR_SHARE) {
            return null;
        }

        if (! in_array($row['vendor_id'], $selectableVendorIds, true)) {
            return null;
        }

        return $row['vendor_id'];
    }

    /**
     * @return array{title: string, count: int, last_used_at: Carbon, vendor_id: ?int, vendor_count: int}|null
     */
    private static function rowForTitle(string $title): ?array
    {
        $normalized = Str::lower(trim($title));

        if ($normalized === '') {
            return null;
        }

        return self::rowsForCurrentUser()->get($normalized);
    }

    /**
     * @return Collection<string, array{title: string, count: int, last_used_at: Carbon, vendor_id: ?int, vendor_count: int}>
     */
    private static function rowsForCurrentUser(): Collection
    {
        $vendorId = auth()->user()?->vendor?->id;

        return Cache::remember(
            self::cacheKey($vendorId),
            now()->addSeconds(self::CACHE_SECONDS),
            fn () => self::buildRows(),
        );
    }

    private static function cacheKey(?int $vendorId): string
    {
        return 'task-title-suggestions:'.($vendorId ?? 'none');
    }

    /**
     * One query against every task on a project this tenant can see — Task
     * carries no tenant scope of its own, Project's (ProjectScope) does —
     * grouped case-insensitively in PHP since the row count per tenant is
     * small and this keeps the grouping portable across SQLite (tests) and
     * MySQL (prod).
     *
     * @return Collection<string, array{title: string, count: int, last_used_at: Carbon, vendor_id: ?int, vendor_count: int}>
     */
    private static function buildRows(): Collection
    {
        return Task::query()
            ->whereIn('project_id', Project::query()->select('id'))
            ->whereNotNull('title')
            ->where('title', '!=', '')
            ->get(['id', 'title', 'vendor_id', 'created_at'])
            ->groupBy(fn (Task $task) => Str::lower(trim($task->title)))
            ->map(function (Collection $tasks): array {
                $spellingCounts = $tasks->countBy(fn (Task $task) => trim($task->title));
                $vendorCounts = $tasks->whereNotNull('vendor_id')->countBy('vendor_id');

                $dominantVendorId = $vendorCounts->isNotEmpty()
                    ? (int) $vendorCounts->sortDesc()->keys()->first()
                    : null;

                return [
                    'title' => (string) $spellingCounts->sortDesc()->keys()->first(),
                    'count' => $tasks->count(),
                    'last_used_at' => $tasks->max('created_at'),
                    'vendor_id' => $dominantVendorId,
                    'vendor_count' => $dominantVendorId !== null ? $vendorCounts[$dominantVendorId] : 0,
                ];
            });
    }
}

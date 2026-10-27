<?php

namespace App\Console\Commands\Permits;

use App\Models\VillageCodes;
use App\Services\Permits\VillageCodeFinder;
use App\Support\Permits\BuildingCodes;
use Illuminate\Console\Command;

/**
 * Re-read every town's building-codes page and record what changed. Runs on
 * the first of each month (routes/console.php); villages move to a new code
 * cycle every few years and announce it on the same page, so a monthly look
 * catches a change well before the next permit. `--find` also retries the
 * towns no page was found for.
 */
class RefreshVillageCodes extends Command
{
    protected $signature = 'permits:refresh-village-codes
        {--town= : One town only}
        {--find : Also look up towns that have no page on file yet}';

    protected $description = 'Re-read each village\'s adopted building codes from its own website';

    public function handle(VillageCodeFinder $finder): int
    {
        $this->seedHandRegistry();

        $rows = VillageCodes::query()->orderBy('town')
            ->when($this->option('town'), fn ($q, $town) => $q->named((string) $town))
            ->get();

        if ($rows->isEmpty() && $this->option('town')) {
            $rows = collect([$finder->pendingRow((string) $this->option('town'))]);
        }

        foreach ($rows as $row) {
            $before = $row->codes ?? [];

            if ($row->source_url) {
                $row = $finder->refresh($row);
            } elseif ($this->option('find') || $this->option('town') || $row->isPending() || $row->status === VillageCodes::STATUS_FAILED) {
                $row = $finder->find($row->town, $row->state);
            } else {
                $this->line(sprintf('%s: no page on file (run with --find)', $row->town));

                continue;
            }

            $this->line(sprintf('%s: %s', $row->town, $this->describe($row, $before)));
        }

        return self::SUCCESS;
    }

    /** The hand-typed towns get rows too, so their pages are re-read like everyone else's. */
    protected function seedHandRegistry(): void
    {
        foreach (BuildingCodes::JURISDICTIONS as $town => $entry) {
            VillageCodes::query()->firstOrCreate(['town' => $town, 'state' => 'IL'], [
                'status' => VillageCodes::STATUS_FOUND,
                'found_by' => VillageCodes::FOUND_BY_HAND,
                'source_url' => $entry['source'],
                'codes' => $entry['codes'],
                'checked_at' => now(),
            ]);
        }
    }

    /** @param  list<string>  $before */
    protected function describe(VillageCodes $row, array $before): string
    {
        if (! $row->isFound()) {
            return match ($row->status) {
                VillageCodes::STATUS_NOT_FOUND => 'no list found on the town\'s website',
                VillageCodes::STATUS_FAILED => 'failed — '.($row->last_error ?: 'unknown error'),
                default => $row->status,
            };
        }

        $after = $row->codes ?? [];
        $added = count(array_diff($after, $before));
        $gone = count(array_diff($before, $after));
        $change = match (true) {
            $before === [] => 'found',
            $added > 0 || $gone > 0 => sprintf('changed (+%d −%d)', $added, $gone),
            (bool) $row->last_error => 'kept — '.$row->last_error,
            default => 'unchanged',
        };

        return sprintf('%d codes, %s · %s', count($after), $change, $row->sourceHost() ?? '');
    }
}

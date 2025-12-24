<?php

namespace App\Console\Commands;

use App\Models\Bid;
use Illuminate\Console\Command;

class FixBidTypes extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bids:fix-types
        {--execute : Apply changes (default is dry-run)}
        {--project-id= : Only process a single project_id}
        {--vendor-id= : Only process a single vendor_id}
    ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Normalize bids.type to 1..N per (project_id, vendor_id) ordered by created_at/id (dry-run by default).';

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $projectId = $this->option('project-id');
        $vendorId = $this->option('vendor-id');

        $this->info($execute ? 'Executing bid type normalization...' : 'Dry-run (no writes).');

        $pairsQuery = Bid::query()
            ->withoutGlobalScopes()
            ->select(['project_id', 'vendor_id'])
            ->distinct()
            ->when($projectId !== null && $projectId !== '', function ($query) use ($projectId) {
                $query->where('project_id', (int) $projectId);
            })
            ->when($vendorId !== null && $vendorId !== '', function ($query) use ($vendorId) {
                $query->where('vendor_id', (int) $vendorId);
            })
            ->orderBy('project_id')
            ->orderBy('vendor_id');

        $changedPairs = 0;
        $changedRows = 0;
        $scannedPairs = 0;

        $pairsQuery->chunk(500, function ($pairs) use ($execute, &$changedPairs, &$changedRows, &$scannedPairs) {
            foreach ($pairs as $pair) {
                $scannedPairs++;

                $bids = Bid::query()
                    ->withoutGlobalScopes()
                    ->where('project_id', $pair->project_id)
                    ->where('vendor_id', $pair->vendor_id)
                    ->orderBy('created_at')
                    ->orderBy('id')
                    ->get(['id', 'type']);

                if ($bids->count() <= 1) {
                    continue;
                }

                $expectedType = 1;
                $pairNeedsChanges = false;

                foreach ($bids as $bid) {
                    if ((int) $bid->type !== $expectedType) {
                        $pairNeedsChanges = true;
                        break;
                    }
                    $expectedType++;
                }

                if (! $pairNeedsChanges) {
                    continue;
                }

                $changedPairs++;

                $expectedType = 1;
                foreach ($bids as $bid) {
                    if ((int) $bid->type !== $expectedType) {
                        $changedRows++;

                        if ($execute) {
                            Bid::query()
                                ->withoutGlobalScopes()
                                ->whereKey($bid->id)
                                ->update(['type' => $expectedType]);
                        }
                    }

                    $expectedType++;
                }
            }
        });

        $this->newLine();
        $this->line('Scanned pairs: ' . number_format($scannedPairs));
        $this->line('Pairs needing changes: ' . number_format($changedPairs));
        $this->line('Rows to change: ' . number_format($changedRows));

        if (! $execute) {
            $this->newLine();
            $this->info('Dry run complete. Re-run with --execute to apply changes.');
        } else {
            $this->newLine();
            $this->info('Execution complete.');
        }

        return self::SUCCESS;
    }
}

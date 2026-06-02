<?php

namespace App\Console\Commands;

use App\Models\Lead;
use Illuminate\Console\Command;

class BackfillMissingLeadStatuses extends Command
{
    protected $signature = 'leads:backfill-missing-statuses {--dry-run}';

    protected $description = 'Create a "New" LeadStatus for any Lead that has no status rows.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $leads = Lead::doesntHave('statuses')->get();

        if ($leads->isEmpty()) {
            $this->info('No leads missing statuses.');

            return self::SUCCESS;
        }

        $this->info(($dryRun ? '[DRY RUN] ' : '')."Found {$leads->count()} lead(s) without statuses.");

        foreach ($leads as $lead) {
            $this->line("  - lead {$lead->id} (vendor {$lead->belongs_to_vendor_id}, {$lead->origin}, {$lead->date->toDateString()})");

            if ($dryRun) {
                continue;
            }

            $lead->statuses()->create([
                'title' => 'New',
                'belongs_to_vendor_id' => $lead->belongs_to_vendor_id,
                'created_at' => $lead->date,
            ]);
        }

        $this->info($dryRun ? 'Dry run complete. No rows written.' : 'Backfill complete.');

        return self::SUCCESS;
    }
}


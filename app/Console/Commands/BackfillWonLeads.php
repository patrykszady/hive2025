<?php

namespace App\Console\Commands;

use App\Models\Lead;
use Illuminate\Console\Command;

/**
 * One-off backfill for leads that converted before the project-created job
 * existed: any lead still sitting in "New" whose client already has a project
 * is really a Won lead.
 */
class BackfillWonLeads extends Command
{
    protected $signature = 'leads:backfill-won
                            {--dry-run : List the leads that would change without writing anything}
                            {--vendor= : Limit to a single vendor id}';

    protected $description = 'Mark New leads as Won when their client already has a project.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $leads = Lead::withoutGlobalScopes()
            ->whereLatestStatus('New')
            ->when($this->option('vendor'), fn ($q, $vendorId) => $q->where('belongs_to_vendor_id', $vendorId))
            ->with(['user.clients', 'last_status'])
            ->orderBy('id')
            ->get();

        $this->info($leads->count().' lead(s) currently in New.');

        $rows = [];
        $converted = 0;

        foreach ($leads as $lead) {
            $client = $lead->resolveClient();

            if (! $client) {
                continue;
            }

            $projectCount = $client->projects()->withoutGlobalScopes()->count();

            if ($projectCount === 0) {
                continue;
            }

            $rows[] = [
                $lead->id,
                mb_strimwidth((string) ($lead->lead_data['name'] ?? '—'), 0, 28, '…'),
                $lead->origin,
                $client->id.' — '.mb_strimwidth($client->name, 0, 24, '…'),
                $projectCount,
            ];

            if (! $dryRun && $lead->setStatus('Won')) {
                $converted++;
            }
        }

        if ($rows === []) {
            $this->info('No New leads have a client with projects — nothing to backfill.');

            return self::SUCCESS;
        }

        $this->table(['Lead', 'Name', 'Origin', 'Client', 'Projects'], $rows);

        $this->info($dryRun
            ? count($rows).' lead(s) would be marked Won. Re-run without --dry-run to apply.'
            : $converted.' lead(s) marked Won.');

        return self::SUCCESS;
    }
}

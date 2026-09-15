<?php

namespace App\Console\Commands;

use App\Jobs\MirrorLeadToGsc;
use App\Models\Lead;
use Illuminate\Console\Command;

/**
 * Push leads born here to gs.construction — the one-time catch-up for
 * leads that predate MirrorLeadToGsc (Angi, Houzz and hive-form leads never
 * reached ss.systems before it), and a re-push after an outage.
 */
class MirrorLeadsToGsc extends Command
{
    protected $signature = 'leads:mirror-to-gsc
        {--days=365 : Leads received within this many days}
        {--dry-run : List what would be pushed, push nothing}';

    protected $description = 'Push leads captured by hive (not born on gs.construction) to gs.construction so ss.systems lists them.';

    public function handle(): int
    {
        if (! MirrorLeadToGsc::configured()) {
            $this->error('GSC_API_URL / GSC_ADMIN_API_TOKEN are not set.');

            return self::FAILURE;
        }

        $leads = Lead::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('external_source')->orWhereNotIn('external_source', MirrorLeadToGsc::BORN_ON_GSC))
            ->where('date', '>=', now()->subDays((int) $this->option('days')))
            ->orderBy('id')
            ->get();

        $this->line(($this->option('dry-run') ? 'DRY RUN — ' : '') . "{$leads->count()} lead(s) born here in the last {$this->option('days')} days.");

        foreach ($leads as $lead) {
            $this->line(sprintf('  lead %-5s %-12s %s', $lead->id, MirrorLeadToGsc::sourceFor($lead), \Illuminate\Support\Str::limit((string) ($lead->lead_data['name'] ?? '—'), 30)));

            if (! $this->option('dry-run')) {
                MirrorLeadToGsc::dispatch($lead->id);
            }
        }

        $this->info($this->option('dry-run') ? 'Nothing pushed.' : "{$leads->count()} push(es) queued.");

        return self::SUCCESS;
    }
}

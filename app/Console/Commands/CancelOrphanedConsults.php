<?php

namespace App\Console\Commands;

use App\Models\Lead;
use Illuminate\Console\Command;

/**
 * Consultations still on the calendar for leads that were removed before
 * removal cancelled them (lead 170 / task 1146, 2026-09-15). For each
 * trashed lead whose contact has no live lead left, the upcoming consult
 * Meet tasks are deleted — which withdraws the calendar invite — and a
 * project that existed only for the consult goes with them.
 */
class CancelOrphanedConsults extends Command
{
    protected $signature = 'leads:cancel-orphaned-consults {--dry-run : List what would be cancelled without touching anything}';

    protected $description = 'Cancel upcoming consultations booked for leads that have since been removed';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $found = 0;

        $trashed = Lead::withoutGlobalScopes()
            ->onlyTrashed()
            ->whereNotNull('user_id')
            ->orderBy('id')
            ->get();

        foreach ($trashed as $lead) {
            // The contact still has a lead we kept: their consult is that lead's.
            if (Lead::withoutGlobalScopes()->whereNull('deleted_at')->where('user_id', $lead->user_id)->exists()) {
                continue;
            }

            $tasks = $lead->bookedConsultTasks();
            if ($tasks->isEmpty()) {
                continue;
            }

            $found += $tasks->count();
            $name = trim((string) ($lead->lead_data['name'] ?? '')) ?: 'Lead #'.$lead->id;

            foreach ($tasks as $task) {
                $this->line(sprintf('  lead #%d %s — task #%d "%s" on %s', $lead->id, $name, $task->id, $task->title, Lead::consultLabel($task)));
            }

            if ($dryRun) {
                continue;
            }

            $cancelled = $lead->cancelBookedConsults();
            foreach ($cancelled['projects'] as $project) {
                $this->line("    removed project \"{$project}\" (held nothing else)");
            }
        }

        if ($found === 0) {
            $this->info('No consultations left behind by removed leads.');
        } else {
            $this->info(($dryRun ? '[dry run] ' : '')."{$found} consultation(s) ".($dryRun ? 'would be' : 'were').' cancelled.');
        }

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\CrewEmailIngest;
use App\Services\CrewLeadEmailService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Replies the mailbox ledger skipped without a lead. Until replies were
 * matched through the addresses ON a message, a homeowner writing from a
 * second account (mdimarco71@hotmail.com, with the outlook address we knew
 * in CC) was "reply" to the ledger and nobody to the CRM: the message lived
 * only in Outlook. This files those onto the lead they belong to, and is
 * safe to re-run — a row filed once carries its lead id and is not looked
 * at again.
 */
class RelinkOrphanedLeadReplies extends Command
{
    protected $signature = 'leads:relink-replies
        {--apply : Write the changes (without this it only reports what it would do)}
        {--limit=500 : Most ledger rows to examine, newest first}';

    protected $description = 'File mailbox replies the ledger skipped without a lead onto the lead they belong to.';

    public function handle(CrewLeadEmailService $service): int
    {
        $apply = (bool) $this->option('apply');

        $rows = CrewEmailIngest::query()
            ->where('status', CrewEmailIngest::STATUS_SKIPPED)
            ->where('skip_reason', 'reply')
            ->whereNull('lead_id')
            ->whereNotNull('from_email')
            ->latest('id')
            ->limit((int) $this->option('limit'))
            ->get();

        if ($rows->isEmpty()) {
            $this->info('Nothing to relink — every skipped reply already has a lead.');

            return self::SUCCESS;
        }

        $this->line($apply
            ? "Relinking among {$rows->count()} skipped repl".($rows->count() === 1 ? 'y' : 'ies').'.'
            : "DRY RUN — {$rows->count()} skipped repl".($rows->count() === 1 ? 'y' : 'ies').' examined. Re-run with --apply to write.');
        $this->newLine();

        $linked = 0;

        foreach ($rows as $row) {
            $lead = $service->leadForMessage([
                'from_email' => $row->from_email,
                'recipients' => (array) ($row->recipients ?? []),
            ]);

            if (! $lead) {
                continue;
            }

            $linked++;

            $this->line(sprintf(
                '  row %-5s %-34s → lead %d (%s)',
                $row->id,
                Str::limit((string) $row->from_email, 32),
                $lead->id,
                Str::limit((string) (((array) $lead->lead_data)['name'] ?? '—'), 26),
            ));

            if ($apply) {
                $service->relinkReply($row);
            }
        }

        $this->newLine();
        $this->info($linked === 0
            ? 'None of them match a lead.'
            : ($apply ? "{$linked} filed." : "{$linked} would be filed. Nothing was written."));

        return self::SUCCESS;
    }
}

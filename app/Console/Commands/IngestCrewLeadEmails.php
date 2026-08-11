<?php

namespace App\Console\Commands;

use App\Services\CrewLeadEmailService;
use Illuminate\Console\Command;

/**
 * Read crew@gs.construction and turn prospect enquiries into leads.
 *
 * Run --dry-run first after any change to the triage rules or the prompt: it
 * classifies exactly as the real run would but writes nothing, so the
 * decisions can be reviewed against a mailbox you know.
 */
class IngestCrewLeadEmails extends Command
{
    protected $signature = 'crew:ingest-leads
        {--dry-run : Classify and report without writing leads or ledger rows}
        {--limit= : Cap messages fetched this run}
        {--days= : Look back this many days instead of using the saved watermark}';

    protected $description = 'Capture prospect enquiries emailed to the crew@ shared mailbox as CRM leads.';

    public function handle(CrewLeadEmailService $service): int
    {
        if (! config('nylas.crew_leads.enabled')) {
            $this->warn('Crew lead capture is disabled (NYLAS_CREW_LEADS_ENABLED).');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $since = $this->option('days') !== null
            ? now()->subDays((int) $this->option('days'))
            : null;

        if ($dryRun) {
            $this->warn('DRY RUN — nothing will be written.');
        }

        $result = $service->ingest(
            dryRun: $dryRun,
            limit: $this->option('limit') !== null ? (int) $this->option('limit') : null,
            since: $since,
        );

        // Leads reply to whichever address last emailed them — sweep the
        // team's own inboxes for those replies too (capture-only; never
        // creates leads).
        if (! $dryRun) {
            $sweep = $service->sweepPersonalInboxes(since: $since);
            $this->line(sprintf(
                '  personal sweep: %d mailbox(es) · %d message(s) · %d reply(ies) filed',
                $sweep['mailboxes'], $sweep['fetched'], $sweep['replies'],
            ));
        }

        if ($result['fetched'] === 0) {
            $this->info('No new messages.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($result['details'] as $d) {
            $note = $d['reason'] ?? ($d['lead_id'] ?? null ? "lead #{$d['lead_id']}" : '');
            if (isset($d['confidence']) && $d['confidence'] > 0) {
                $note = trim($note . ' (' . number_format($d['confidence'], 2) . ')');
            }
            $rows[] = [
                match ($d['status']) {
                    'lead' => 'LEAD',
                    'failed' => 'FAIL',
                    default => 'skip',
                },
                \Illuminate\Support\Str::limit($d['from'] ?? '', 30, ''),
                \Illuminate\Support\Str::limit($d['subject'] ?? '', 46, ''),
                \Illuminate\Support\Str::limit((string) $note, 34, ''),
            ];
        }

        $this->table(['', 'From', 'Subject', 'Outcome'], $rows);
        $this->line(sprintf(
            '  fetched %d · leads %d · skipped %d · failed %d',
            $result['fetched'], $result['leads'], $result['skipped'], $result['failed'],
        ));

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}

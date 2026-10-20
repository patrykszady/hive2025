<?php

namespace App\Console\Commands;

use App\Services\Emails\StakeholderEmailSync;
use Illuminate\Console\Command;

/**
 * Read the connected mailboxes and keep the email with project stakeholders
 * (see App\Services\Emails\StakeholderEmailSync). Scheduled every five
 * minutes; --days re-reads a window (the store skips what it already has).
 */
class SyncStakeholderEmails extends Command
{
    protected $signature = 'emails:sync
        {--days= : Read this many days back instead of from the watermark}';

    protected $description = 'Store email with project stakeholders from every connected mailbox, inbox and sent.';

    public function handle(StakeholderEmailSync $sync): int
    {
        if (! config('emails.stakeholder_sync.enabled')) {
            $this->warn('Stakeholder email sync is off (STAKEHOLDER_EMAIL_SYNC).');

            return self::SUCCESS;
        }

        $report = $sync->run($this->option('days') !== null ? (int) $this->option('days') : null);

        $this->table(['Mailbox', 'Folder', 'Read', 'Stored', 'Error'], array_map(fn (array $row) => [
            $row['mailbox'], $row['folder'], $row['fetched'], $row['stored'], $row['error'] ?? '',
        ], $report));

        return collect($report)->contains(fn (array $row) => $row['error'] !== null) ? self::FAILURE : self::SUCCESS;
    }
}

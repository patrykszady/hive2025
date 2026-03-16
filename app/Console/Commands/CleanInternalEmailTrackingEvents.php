<?php

namespace App\Console\Commands;

use App\Models\EmailTracking;
use Illuminate\Console\Command;

class CleanInternalEmailTrackingEvents extends Command
{
    protected $signature = 'email-tracking:clean-internal
                            {--dry-run : Show count without deleting}';

    protected $description = 'Remove opened/clicked email tracking events from internal domains (gs.construction, hive.contractors, etc.)';

    public function handle(): int
    {
        $domains = (array) config('email_tracking.internal_domains', []);

        if (empty($domains)) {
            $this->error('No internal domains configured in config/email_tracking.php.');

            return self::FAILURE;
        }

        $this->info('Internal domains: ' . implode(', ', $domains));

        $query = EmailTracking::withoutGlobalScopes()
            ->whereIn('event_type', ['opened', 'link_clicked'])
            ->where(function ($q) use ($domains) {
                foreach ($domains as $domain) {
                    $q->orWhere('recipient_emails', 'LIKE', '%@' . $domain . '%');
                }
            });

        $count = $query->count();

        if ($count === 0) {
            $this->info('No internal domain open/click events found.');

            return self::SUCCESS;
        }

        $this->info("Found {$count} open/click events from internal domains.");

        if ($this->option('dry-run')) {
            $this->warn('Dry run — no records deleted.');

            return self::SUCCESS;
        }

        if (! $this->confirm("Delete {$count} records?")) {
            $this->info('Aborted.');

            return self::SUCCESS;
        }

        $deleted = $query->delete();

        $this->info("Deleted {$deleted} records.");

        return self::SUCCESS;
    }
}

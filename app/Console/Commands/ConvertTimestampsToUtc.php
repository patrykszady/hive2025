<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ConvertTimestampsToUtc extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'email-tracking:convert-timestamps-to-utc 
                            {--dry-run : Show what would be converted without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Convert email_tracking timestamps from MST/Local timezone to UTC';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Converting email_tracking timestamps from MST/Local to UTC...');
        $this->newLine();

        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
            $this->newLine();
        }

        // Check if table exists
        if (!DB::getSchemaBuilder()->hasTable('email_tracking')) {
            $this->error('email_tracking table does not exist!');
            return Command::FAILURE;
        }

        // Convert webhook events (replied, opened, etc.)
        $this->processWebhookEvents($dryRun);

        // Convert sent events
        $this->processSentEvents($dryRun);

        $this->newLine();
        $this->info('✓ Timestamp conversion completed!');

        if ($dryRun) {
            $this->warn('This was a dry run. Run without --dry-run to apply changes.');
        }

        return Command::SUCCESS;
    }

    /**
     * Process webhook events (replied, opened, etc.)
     */
    protected function processWebhookEvents(bool $dryRun): void
    {
        $this->line('Processing webhook events (replied, opened, link_clicked, bounced, rejected)...');

        $count = DB::table('email_tracking')
            ->whereIn('event_type', ['replied', 'replied_outgoing', 'opened', 'link_clicked', 'bounced', 'rejected'])
            ->count();

        if ($count === 0) {
            $this->line('  ⊙ No webhook events to convert');
            return;
        }

        if ($dryRun) {
            $this->line("  ○ Would convert {$count} webhook event records (add 7 hours to event_at, created_at, updated_at)");
        } else {
            DB::statement("
                UPDATE email_tracking 
                SET event_at = DATE_ADD(event_at, INTERVAL 7 HOUR),
                    created_at = DATE_ADD(created_at, INTERVAL 7 HOUR),
                    updated_at = DATE_ADD(updated_at, INTERVAL 7 HOUR)
                WHERE event_type IN ('replied', 'replied_outgoing', 'opened', 'link_clicked', 'bounced', 'rejected')
            ");
            $this->info("  ✓ Converted {$count} webhook event records");
        }
    }

    /**
     * Process sent events created by the application
     */
    protected function processSentEvents(bool $dryRun): void
    {
        $this->line('Processing sent events...');

        // Sent events need 13 hours added (MST UTC-7 plus 6 hour adjustment)
        $count = DB::table('email_tracking')
            ->where('event_type', 'sent')
            ->count();

        if ($count === 0) {
            $this->line('  ⊙ No sent events to convert');
            return;
        }

        if ($dryRun) {
            $this->line("  ○ Would convert {$count} sent event records (add 13 hours to event_at, created_at, updated_at)");
        } else {
            DB::statement("
                UPDATE email_tracking 
                SET event_at = DATE_ADD(event_at, INTERVAL 13 HOUR),
                    created_at = DATE_ADD(created_at, INTERVAL 13 HOUR),
                    updated_at = DATE_ADD(updated_at, INTERVAL 13 HOUR)
                WHERE event_type = 'sent'
            ");
            $this->info("  ✓ Converted {$count} sent event records");
        }
    }
}

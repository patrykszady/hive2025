<?php

namespace App\Console\Commands;

use App\Models\EmailTracking;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanDuplicateReplies extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'email-tracking:clean-duplicate-replies {--dry-run : Preview changes without applying them}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove duplicate replied_outgoing events that were created within 30 seconds of a sent event.
    
    When you send an email via Nylas, it fires two webhooks:
    1. message.sent → correctly tracked as "sent"
    2. thread.replied with from_self:true → incorrectly created "replied_outgoing"
    
    This command finds and removes those duplicate replied_outgoing events that occurred
    within 30 seconds of a sent event in the same thread (indicating they are duplicates
    of the sent event, not legitimate replies).';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');

        if ($isDryRun) {
            $this->info('Running in dry-run mode. No changes will be applied.');
        }

        // Find duplicate replied_outgoing events:
        // - Created within 30 seconds after a sent event
        // - In the same thread
        // - With different message IDs (to avoid deleting legitimate replies)
        $duplicates = DB::select("
            SELECT 
                reply.id as duplicate_id,
                reply.event_at as duplicate_at,
                reply.nylas_message_id as duplicate_msg_id,
                sent.id as sent_id,
                sent.event_at as sent_at,
                sent.nylas_message_id as sent_msg_id,
                TIMESTAMPDIFF(SECOND, sent.event_at, reply.event_at) as seconds_between
            FROM email_tracking sent
            JOIN email_tracking reply ON sent.nylas_thread_id = reply.nylas_thread_id
            WHERE sent.event_type = 'sent'
              AND reply.event_type = 'replied_outgoing'
              AND TIMESTAMPDIFF(SECOND, sent.event_at, reply.event_at) BETWEEN 0 AND 30
              AND sent.nylas_message_id != reply.nylas_message_id
              AND reply.id > sent.id
            ORDER BY reply.id DESC
        ");

        if (empty($duplicates)) {
            $this->info('No duplicate replied_outgoing events found.');
            return 0;
        }

        $this->info('Found ' . count($duplicates) . ' duplicate replied_outgoing event(s):');
        $this->newLine();

        $table = [];
        foreach ($duplicates as $duplicate) {
            $table[] = [
                'Duplicate ID' => $duplicate->duplicate_id,
                'Sent ID' => $duplicate->sent_id,
                'Seconds Apart' => $duplicate->seconds_between,
                'Duplicate At' => $duplicate->duplicate_at,
                'Sent At' => $duplicate->sent_at,
            ];
        }

        $this->table(
            ['Duplicate ID', 'Sent ID', 'Seconds Apart', 'Duplicate At', 'Sent At'],
            $table
        );

        if ($isDryRun) {
            $this->info('Dry-run complete. Run without --dry-run to delete these records.');
            return 0;
        }

        if (!$this->confirm('Do you want to delete these duplicate records?', true)) {
            $this->info('Operation cancelled.');
            return 0;
        }

        $duplicateIds = collect($duplicates)->pluck('duplicate_id')->toArray();
        $deleted = EmailTracking::whereIn('id', $duplicateIds)->delete();

        $this->info("Successfully deleted {$deleted} duplicate replied_outgoing event(s).");

        return 0;
    }
}

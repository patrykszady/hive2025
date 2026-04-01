<?php

namespace App\Console\Commands;

use App\Models\SmsMessage;
use Illuminate\Console\Command;

class FixJenniferMisroutedMessages extends Command
{
    protected $signature = 'fix:jennifer-misrouted-messages
                            {--dry-run : Preview changes without executing}';

    protected $description = 'Move Jennifer\'s misrouted 1:1 messages from group thread 197 to her direct thread 17';

    public function handle(): int
    {
        $messageIds = [40770, 40844];
        $sourceThreadId = 197;
        $targetThreadId = 17;

        $messages = SmsMessage::whereIn('id', $messageIds)
            ->where('thread_id', $sourceThreadId)
            ->get();

        if ($messages->isEmpty()) {
            $this->info('No messages to move — already fixed.');

            return self::SUCCESS;
        }

        $this->info("Found {$messages->count()} message(s) to move from thread #{$sourceThreadId} → #{$targetThreadId}:");
        foreach ($messages as $msg) {
            $this->line("  #{$msg->id} | {$msg->from_number} | {$msg->created_at} | " . substr($msg->text, 0, 60));
        }

        if ($this->option('dry-run')) {
            $this->warn('Dry run — no changes made.');

            return self::SUCCESS;
        }

        SmsMessage::whereIn('id', $messages->pluck('id'))
            ->update(['thread_id' => $targetThreadId]);

        $this->info("Moved {$messages->count()} message(s) to thread #{$targetThreadId}.");

        return self::SUCCESS;
    }
}

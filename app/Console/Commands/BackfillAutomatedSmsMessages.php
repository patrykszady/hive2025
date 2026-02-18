<?php

namespace App\Console\Commands;

use App\Models\SmsMessage;
use Illuminate\Console\Command;

class BackfillAutomatedSmsMessages extends Command
{
    protected $signature = 'sms:backfill-automated-messages {--dry-run : Show what would be updated without making changes}';

    protected $description = 'Set sent_by_user_id to NULL on automated consent and welcome SMS messages so they display as "GS Crew"';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $query = SmsMessage::where('direction', SmsMessage::DIRECTION_OUTBOUND)
            ->whereNotNull('sent_by_user_id')
            ->where(function ($q) {
                $q->where('text', 'like', '%Reply START to activate communication with GS Construction%')
                  ->orWhere('text', 'like', '%GS Construction welcomes you to our project msg thread%');
            });

        $count = $query->count();

        if ($count === 0) {
            $this->info('No automated messages found that need updating.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info("[Dry run] Would update {$count} automated message(s) to sent_by_user_id = NULL.");

            $query->each(function (SmsMessage $msg) {
                $this->line("  ID {$msg->id} | thread {$msg->thread_id} | user {$msg->sent_by_user_id} | " . \Illuminate\Support\Str::limit($msg->text, 80));
            });

            return self::SUCCESS;
        }

        $updated = $query->update(['sent_by_user_id' => null]);

        $this->info("Updated {$updated} automated message(s) — sent_by_user_id set to NULL.");

        return self::SUCCESS;
    }
}

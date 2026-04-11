<?php

namespace App\Console\Commands;

use App\Events\SmsMessageReceived;
use App\Jobs\SendGroupMms;
use App\Models\SmsMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendScheduledSmsMessages extends Command
{
    protected $signature = 'sms:send-scheduled';

    protected $description = 'Send SMS messages that are due based on their scheduled_at time';

    public function handle(): int
    {
        $messages = SmsMessage::query()
            ->where('status', 'scheduled')
            ->where('scheduled_at', '<=', now())
            ->get();

        if ($messages->isEmpty()) {
            $this->info('No scheduled messages due.');

            return self::SUCCESS;
        }

        foreach ($messages as $message) {
            $message->update(['status' => 'sending']);

            SendGroupMms::dispatch($message->id);

            $message->thread?->update(['last_activity_at' => now()]);

            try {
                SmsMessageReceived::dispatch($message->thread_id);
            } catch (\Throwable $e) {
                Log::warning('Scheduled SMS broadcast failed', [
                    'message_id' => $message->id,
                    'error' => $e->getMessage(),
                ]);
            }

            if ($message->sent_by_user_id) {
                \App\Jobs\SendOutboundSmsBrowserNotifications::dispatch($message->id, $message->sent_by_user_id);
            }
        }

        $this->info("Sent {$messages->count()} scheduled message(s).");

        return self::SUCCESS;
    }
}

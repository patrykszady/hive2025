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
            ->orderBy('scheduled_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        if ($messages->isEmpty()) {
            $this->info('No scheduled messages due.');

            return self::SUCCESS;
        }

        // When several messages in the same thread are due at the same time,
        // stagger the actual sends by 10 seconds each so they arrive in the
        // order they were composed (and the recipient sees them in order).
        $threadOffsets = [];

        foreach ($messages as $message) {
            $threadId = $message->thread_id;
            $delaySeconds = $threadOffsets[$threadId] ?? 0;
            $threadOffsets[$threadId] = $delaySeconds + 10;

            $message->update(['status' => 'sending']);

            $job = SendGroupMms::dispatch($message->id);
            if ($delaySeconds > 0) {
                $job->delay(now()->addSeconds($delaySeconds));
            }

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

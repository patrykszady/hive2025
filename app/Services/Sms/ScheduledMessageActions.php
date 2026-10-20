<?php

namespace App\Services\Sms;

use App\Livewire\Sms\SmsNewThread;
use App\Models\SmsMessage;
use App\Models\Task;
use Illuminate\Support\Facades\Log;

/**
 * Cancel / send-now for a scheduled (not yet delivered) message — shared by
 * the web conversation
 * (App\Livewire\Sms\SmsConversation::cancelScheduledMessage()/sendScheduledNow())
 * and the app's thread message endpoints. Only ever called on a message
 * whose status is still 'scheduled'.
 */
class ScheduledMessageActions
{
    public function cancel(SmsMessage $message): void
    {
        $message->delete();
    }

    /**
     * Send a scheduled message right now instead of waiting for its
     * scheduled_at time — re-stamps the sender's current signature, flags
     * any tasks the schedule blast named as requested, and fires the same
     * broadcast/notification jobs a normal send does.
     */
    public function sendNow(SmsMessage $message, int $actorId): void
    {
        $rawPayload = is_array($message->raw_payload) ? $message->raw_payload : [];

        $taskIds = collect((array) ($rawPayload['scheduled_task_ids'] ?? []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->values()
            ->all();

        if ($taskIds !== [] && $message->thread?->subject_vendor_id) {
            Task::whereIn('id', $taskIds)
                ->where('vendor_id', $message->thread->subject_vendor_id)
                ->whereNull('vendor_status')
                ->update([
                    'vendor_status' => Task::VENDOR_STATUS_REQUESTED,
                ]);
        }

        $updates = ['status' => 'sending', 'scheduled_at' => null];

        $newSignature = SmsNewThread::getSignature($actorId);
        $existingSignature = $this->extractSignatureLine((string) ($message->text ?? ''));

        if ($existingSignature !== null && $existingSignature !== $newSignature) {
            $body = rtrim(preg_replace('/\n?-(?:PS|GS|GSC)$/', '', rtrim((string) $message->text)));
            $updates['text'] = $body . "\n" . $newSignature;
        }

        $updates['sent_by_user_id'] = $actorId;

        $message->update($updates);

        \App\Jobs\SendGroupMms::dispatch($message->id);

        $message->thread?->update(['last_activity_at' => now()]);

        try {
            \App\Events\SmsMessageReceived::dispatch($message->thread_id);
        } catch (\Throwable $e) {
            Log::warning('SMS broadcast failed', ['message_id' => $message->id, 'error' => $e->getMessage()]);
        }

        if ($message->sent_by_user_id) {
            \App\Jobs\SendOutboundSmsBrowserNotifications::dispatch($message->id, $message->sent_by_user_id);
        }
    }

    private function extractSignatureLine(string $text): ?string
    {
        if (preg_match('/(?:^|\n)(-(?:PS|GS|GSC))$/', trim($text), $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}

<?php

namespace App\Services\Sms;

use App\Livewire\Sms\SmsNewThread;
use App\Models\SmsGroupThread;
use App\Models\SmsMessage;
use App\Models\Task;
use App\Services\SmsTranslationService;
use Illuminate\Support\Facades\Log;

/**
 * Cancel / send-now / edit for a scheduled (not yet delivered) message —
 * shared by the web conversation
 * (App\Livewire\Sms\SmsConversation::cancelScheduledMessage()/sendScheduledNow()/
 * saveEditedScheduledMessage()) and the app's thread message endpoints. Only
 * ever called on a message whose status is still 'scheduled'.
 */
class ScheduledMessageActions
{
    public function cancel(SmsMessage $message): void
    {
        $message->delete();
    }

    /**
     * Edit a scheduled message's text before it sends — the web composer's
     * only scheduled-edit: the send time itself is never editable here, only
     * the body. The new text goes out as typed (sms.translate_outbound is off:
     * texts leave in English), re-translated only when that switch is on and
     * the languages differ, and the crew signature line the original text
     * carried is re-appended.
     */
    public function edit(SmsMessage $message, string $newText, SmsTranslationService $translator): SmsMessage
    {
        $newOriginalText = trim($newText);
        $rawPayload = is_array($message->raw_payload) ? $message->raw_payload : [];

        $senderLanguage = $translator->normalizeLanguage((string) (
            $rawPayload['sender_language'] ?? (auth()->user()?->preferred_language ?: 'English')
        ));
        $recipientLanguage = $translator->normalizeLanguage((string) (
            $rawPayload['recipient_language'] ?? $this->fallbackRecipientLanguage($message->thread)
        ));

        $outboundText = $newOriginalText;
        if ($newOriginalText !== '' && config('sms.translate_outbound') && strcasecmp($senderLanguage, $recipientLanguage) !== 0) {
            $outboundText = $translator->translate($newOriginalText, $recipientLanguage, $senderLanguage);
        }

        $signatureLine = $this->extractSignatureLine((string) ($message->text ?? ''));
        if ($signatureLine !== null) {
            $outboundText .= "\n{$signatureLine}";
        }

        $rawPayload['original_text'] = $newOriginalText;
        $rawPayload['sender_language'] = $senderLanguage;
        $rawPayload['recipient_language'] = $recipientLanguage;

        $message->update([
            'text' => $outboundText,
            'raw_payload' => $rawPayload,
        ]);

        return $message->fresh();
    }

    /**
     * Mirrors App\Livewire\Sms\SmsConversation::threadRecipientLanguage()'s
     * fallback when the message's own raw_payload has no recipient_language
     * recorded yet.
     */
    private function fallbackRecipientLanguage(?SmsGroupThread $thread): string
    {
        if (! $thread) {
            return 'English';
        }

        if ($thread->subject_vendor_id) {
            $language = $thread->subjectVendor?->users
                ?->pluck('preferred_language')
                ->filter(fn ($value) => is_string($value) && trim($value) !== '')
                ->first();

            return (string) ($language ?: 'English');
        }

        $language = $thread->client?->users
            ?->pluck('preferred_language')
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->first();

        return (string) ($language ?: 'English');
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

        // scheduled_at becomes the moment it actually goes out: created_at
        // stays when it was typed, and the thread shows and orders the text
        // by scheduled_at (SmsMessage::shown_at) — Patryk, 2026-10-27: a text
        // scheduled at night for 8 am shows at 8 am, "created_at should remain".
        $updates = ['status' => 'sending', 'scheduled_at' => now()];

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

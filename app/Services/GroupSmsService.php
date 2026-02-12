<?php

namespace App\Services;

use App\Events\SmsMessageReceived;
use App\Models\SmsGroupThread;
use App\Models\SmsMessage;

class GroupSmsService
{
    protected string $from;

    public function __construct()
    {
        $this->from = config('services.telnyx.from');
    }

    /**
     * Send a group MMS message to all participants in a thread.
     *
     * @param  array<string>  $mediaUrls  Public URLs to media attachments
     */
    public function sendToThread(SmsGroupThread $thread, string $text, array $mediaUrls = [], ?int $sentByUserId = null): SmsMessage
    {
        $participants = $thread->participants ?? [];

        $message = SmsMessage::create([
            'thread_id' => $thread->id,
            'provider' => 'telnyx',
            'direction' => SmsMessage::DIRECTION_OUTBOUND,
            'from_number' => $this->from,
            'to_numbers' => $participants,
            'text' => $text,
            'media_urls' => $mediaUrls ?: null,
            'status' => 'sending',
            'sent_by_user_id' => $sentByUserId,
        ]);

        // Dispatch to queue — job handles the Telnyx API call with rate limiting
        \App\Jobs\SendGroupMms::dispatch($message->id);

        $thread->update(['last_activity_at' => now()]);

        // Broadcast immediately so the sender sees the message in the UI
        SmsMessageReceived::dispatch($thread->id);

        return $message;
    }

    /**
     * Send a new message to a list of phone numbers, creating a thread.
     *
     * @param  array<string>  $phoneNumbers  E.164 format
     */
    public function sendNewGroup(array $phoneNumbers, string $text, ?int $projectId = null, ?int $clientId = null, ?int $sentByUserId = null): SmsGroupThread
    {
        $thread = SmsGroupThread::create([
            'from_number' => $this->from,
            'participants' => $phoneNumbers,
            'project_id' => $projectId,
            'client_id' => $clientId,
            'last_activity_at' => now(),
        ]);

        $this->sendToThread($thread, $text, [], $sentByUserId);

        return $thread;
    }

    /**
     * Format a phone number to E.164.
     */
    public static function formatE164(string $phone): string
    {
        $digits = preg_replace('/[^0-9]/', '', $phone);

        if (strlen($digits) === 10) {
            return '+1' . $digits;
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return '+' . $digits;
        }

        return '+' . $digits;
    }
}

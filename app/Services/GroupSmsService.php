<?php

namespace App\Services;

use App\Events\SmsMessageReceived;
use App\Models\SmsGroupThread;
use App\Models\SmsMessage;
use App\Models\SmsThreadParticipant;

class GroupSmsService
{
    public const START_CONSENT_TEXT = 'Reply START to activate communication with GS Construction. Msg & data rates may apply. Message frequency varies. Reply STOP to opt out, HELP for help.';

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
        // Wrapped in try-catch so message sending never fails if Reverb is down
        try {
            SmsMessageReceived::dispatch($thread->id);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('SMS broadcast failed (Reverb may be down)', [
                'thread_id' => $thread->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Notify other vendor users via browser push when a team member replies
        if ($sentByUserId) {
            \App\Jobs\SendOutboundSmsBrowserNotifications::dispatch($message->id, $sentByUserId);
        }

        return $message;
    }

    /**
     * Send a new message to a list of phone numbers, creating a thread.
     *
     * @param  array<string>  $phoneNumbers  E.164 format
     */
    public function sendNewGroup(array $phoneNumbers, string $text, ?int $projectId = null, ?int $clientId = null, ?int $sentByUserId = null): SmsGroupThread
    {
        $normalizedPhoneNumbers = collect($phoneNumbers)
            ->map(fn (string $phone): string => self::formatE164($phone))
            ->unique()
            ->values()
            ->all();

        $thread = SmsGroupThread::create([
            'from_number' => $this->from,
            'participants' => $normalizedPhoneNumbers,
            'project_id' => $projectId,
            'client_id' => $clientId,
            'last_activity_at' => now(),
        ]);

        foreach ($normalizedPhoneNumbers as $phoneNumber) {
            SmsThreadParticipant::create([
                'thread_id' => $thread->id,
                'phone_number' => $phoneNumber,
            ]);
        }

        // Automated consent message should not be attributed to the triggering user
        $this->sendToThread($thread, $this->buildConsentMessage($thread), [], null);
        $thread->update(['opt_in_prompt_sent_at' => now()]);

        return $thread;
    }

    public function markParticipantOptedInAndSendWelcomeIfReady(SmsGroupThread $thread, string $phoneNumber, ?int $sentByUserId = null): bool
    {
        $normalizedPhone = self::formatE164($phoneNumber);

        $participant = $thread->threadParticipants()
            ->where('phone_number', $normalizedPhone)
            ->first();

        if (! $participant) {
            return false;
        }

        if ($participant->opted_in_at === null) {
            $participant->update(['opted_in_at' => now()]);
        }

        if ($thread->welcome_sent_at !== null || ! $thread->allParticipantsOptedIn()) {
            return false;
        }

        $this->sendToThread($thread, $this->buildWelcomeMessage($thread), [], $sentByUserId);
        $thread->update(['welcome_sent_at' => now()]);

        return true;
    }

    public function resendConsentPrompt(SmsGroupThread $thread): bool
    {
        if (! $thread->hasPendingOptIn()) {
            return false;
        }

        // Automated consent message should not be attributed to a user
        $this->sendToThread($thread, $this->buildConsentMessage($thread), [], null);
        $thread->update(['opt_in_prompt_sent_at' => now()]);

        return true;
    }

    public static function isStartKeyword(string $text): bool
    {
        return preg_match('/\bSTART\b/i', trim($text)) === 1;
    }

    private function buildWelcomeMessage(SmsGroupThread $thread): string
    {
        return $this->buildGreeting($thread) . "\n"
            . "GS Construction welcomes you to our project msg thread. "
            . "Msgs will be tagged with \"-PS\" for Patryk's replies, \"-GS\" for Grzegorz's, and our automated \"GS Crew\" replies by \"-GSC\". "
            . "Please save this number as \"GS Construction\" in your contacts list. "
            . "You can always also text or call us at this number."
            . "\n-GSC";
    }

    private function buildConsentMessage(SmsGroupThread $thread): string
    {
        return $this->buildGreeting($thread) . "\n" . self::START_CONSENT_TEXT . "\n-GSC";
    }

    private function buildGreeting(SmsGroupThread $thread): string
    {
        $recipientNames = $this->resolveRecipientNames($thread);

        if ($recipientNames === '') {
            return 'Hi there,';
        }

        return "Hi {$recipientNames},";
    }

    private function resolveRecipientNames(SmsGroupThread $thread): string
    {
        $thread->loadMissing('client.users');

        $participants = collect($thread->participants ?? [])
            ->map(fn (string $phone): string => self::formatE164($phone));

        $names = collect();

        $client = $thread->client;
        if (! $client) {
            return '';
        }

        $homePhone = $client->getRawOriginal('home_phone');
        if (is_string($homePhone) && $homePhone !== '') {
            $homePhoneE164 = self::formatE164($homePhone);
            if ($participants->contains($homePhoneE164)) {
                $names->push($client->name);
            }
        }

        foreach ($client->users as $user) {
            $cellPhone = $user->getRawOriginal('cell_phone');
            if (! is_string($cellPhone) || $cellPhone === '') {
                continue;
            }

            $cellPhoneE164 = self::formatE164($cellPhone);
            if (! $participants->contains($cellPhoneE164)) {
                continue;
            }

            if (is_string($user->first_name) && $user->first_name !== '') {
                $names->push($user->first_name);
            }
        }

        return $names->filter()->unique()->implode(' & ');
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

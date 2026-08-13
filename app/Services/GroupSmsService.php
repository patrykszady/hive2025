<?php

namespace App\Services;

use App\Events\SmsMessageReceived;
use App\Models\SmsGroupThread;
use App\Models\SmsMessage;
use App\Models\SmsThreadParticipant;
use Carbon\Carbon;

class GroupSmsService
{
    public const START_CONSENT_TEXT = 'Reply START to activate communication with GS Construction. Msg & data rates may apply. Message frequency varies. Reply STOP to opt out, HELP for help.';

    /**
     * Static translations of the consent prompt. START/STOP/HELP keywords must
     * stay in English — carriers only recognize the English keywords.
     *
     * @var array<string, string>
     */
    private const CONSENT_TEXT_TRANSLATIONS = [
        'Spanish' => 'Responde START para activar la comunicación con GS Construction. Pueden aplicarse tarifas de mensajes y datos. La frecuencia de los mensajes varía. Responde STOP para darte de baja o HELP para recibir ayuda.',
        'Polish' => 'Odpisz START, aby aktywować komunikację z GS Construction. Mogą obowiązywać opłaty za wiadomości i dane. Częstotliwość wiadomości może się różnić. Odpisz STOP, aby zrezygnować, lub HELP, aby uzyskać pomoc.',
    ];

    private const WELCOME_BODY = 'GS Construction welcomes you to our project msg thread. '
        . "Msgs will be tagged with \"-GS\" for Gregory's replies, \"-PS\" for Patryk's, and our automated \"GS Crew\" replies by \"-GSC\". "
        . 'Please save this number as "GS Construction" in your contacts list. '
        . 'You can always also text or call us at this number.';

    /** @var array<string, string> */
    private const WELCOME_BODY_TRANSLATIONS = [
        'Spanish' => 'GS Construction te da la bienvenida a nuestro hilo de mensajes del proyecto. '
            . 'Los mensajes llevarán la etiqueta "-GS" para las respuestas de Gregory, "-PS" para las de Patryk y "-GSC" para las respuestas automáticas de "GS Crew". '
            . 'Por favor, guarda este número como "GS Construction" en tu lista de contactos. '
            . 'También puedes escribirnos o llamarnos a este número en cualquier momento.',
        'Polish' => 'GS Construction wita Cię w naszym wątku wiadomości projektowych. '
            . 'Wiadomości będą oznaczone "-GS" dla odpowiedzi Gregory\'ego, "-PS" dla odpowiedzi Patryka, a automatyczne odpowiedzi "GS Crew" — "-GSC". '
            . 'Zapisz ten numer jako "GS Construction" na liście kontaktów. '
            . 'Zawsze możesz też do nas napisać lub zadzwonić pod ten numer.',
    ];

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
    public function sendToThread(
        SmsGroupThread $thread,
        string $text,
        array $mediaUrls = [],
        ?int $sentByUserId = null,
        ?Carbon $scheduledAt = null,
        ?array $rawPayload = null,
        bool $scheduleOnly = false,
    ): SmsMessage
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
            'raw_payload' => $rawPayload,
            'status' => ($scheduledAt || $scheduleOnly) ? 'scheduled' : 'sending',
            'sent_by_user_id' => $sentByUserId,
            'scheduled_at' => $scheduledAt,
        ]);

        if ($scheduledAt || $scheduleOnly) {
            return $message;
        }

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
    public function sendNewGroup(array $phoneNumbers, string $text, ?int $projectId = null, ?int $clientId = null, ?int $sentByUserId = null, ?int $vendorId = null, ?int $subjectVendorId = null): SmsGroupThread
    {
        $normalizedPhoneNumbers = collect($phoneNumbers)
            ->map(fn (string $phone): string => self::formatE164($phone))
            ->unique()
            ->values();

        // Office lines never ride along in group messages. A deliberate
        // solo text to a business number stays possible — only drop them
        // when actual people are on the thread.
        if ($normalizedPhoneNumbers->count() > 1) {
            $withoutBusinessLines = $normalizedPhoneNumbers
                ->reject(fn (string $phone): bool => self::isBusinessLine($phone))
                ->values();

            if ($withoutBusinessLines->isNotEmpty()) {
                $normalizedPhoneNumbers = $withoutBusinessLines;
            }
        }

        $normalizedPhoneNumbers = $normalizedPhoneNumbers->all();

        $alreadyOptedInPhones = SmsThreadParticipant::query()
            ->whereIn('phone_number', $normalizedPhoneNumbers)
            ->whereNotNull('opted_in_at')
            ->pluck('phone_number')
            ->map(fn (string $phone): string => self::formatE164($phone))
            ->unique()
            ->values()
            ->all();

        $thread = SmsGroupThread::create([
            'from_number' => $this->from,
            'participants' => $normalizedPhoneNumbers,
            'project_id' => $projectId,
            'client_id' => $clientId,
            'vendor_id' => $vendorId,
            'subject_vendor_id' => $subjectVendorId,
            'last_activity_at' => now(),
        ]);

        foreach ($normalizedPhoneNumbers as $phoneNumber) {
            $isBusinessLine = self::isBusinessLine($phoneNumber);

            SmsThreadParticipant::create([
                'thread_id' => $thread->id,
                'phone_number' => $phoneNumber,
                'opted_in_at' => ($isBusinessLine || in_array($phoneNumber, $alreadyOptedInPhones, true)) ? now() : null,
                'manual_opt_in_reason' => $isBusinessLine ? 'Office line — consent not required' : null,
            ]);
        }

        // Automated consent message should not be attributed to the triggering user
        $this->sendToThread($thread, $this->buildConsentMessage($thread), [], null);
        $thread->update(['opt_in_prompt_sent_at' => now()]);

        return $thread;
    }

    /**
     * A company's own front-desk number can't reply START and doesn't need
     * to — SMS consent applies to people's cell phones, not office lines.
     * Matches any vendor's business_phone (compared on last 10 digits).
     */
    public static function isBusinessLine(string $phoneNumber): bool
    {
        $digits = substr(preg_replace('/\D/', '', $phoneNumber), -10);

        if (strlen($digits) < 10) {
            return false;
        }

        return \App\Models\Vendor::query()
            ->whereNotNull('business_phone')
            ->pluck('business_phone')
            ->contains(fn ($phone) => substr(preg_replace('/\D/', '', (string) $phone), -10) === $digits);
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

        // Welcome message is automated (tagged -GSC), so don't attribute it to the triggering user
        $this->sendToThread($thread, $this->buildWelcomeMessage($thread), [], null);
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
        $language = $this->threadRecipientLanguage($thread);
        $body = self::WELCOME_BODY_TRANSLATIONS[$language] ?? self::WELCOME_BODY;

        return $this->buildGreeting($thread, language: $language) . "\n" . $body . "\n-GSC";
    }

    private function buildConsentMessage(SmsGroupThread $thread): string
    {
        $language = $this->threadRecipientLanguage($thread);
        $consentText = self::CONSENT_TEXT_TRANSLATIONS[$language] ?? self::START_CONSENT_TEXT;

        return $this->buildGreeting($thread, pendingOnly: true, language: $language) . "\n" . $consentText . "\n-GSC";
    }

    /**
     * Preferred language of the thread's recipients (subject vendor's or
     * client's users), mirroring SmsConversation::threadRecipientLanguage().
     */
    private function threadRecipientLanguage(SmsGroupThread $thread): string
    {
        $thread->loadMissing('client.users', 'subjectVendor.users');

        $users = $thread->subject_vendor_id
            ? $thread->subjectVendor?->users
            : $thread->client?->users;

        $language = $users
            ?->pluck('preferred_language')
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->first();

        return app(SmsTranslationService::class)->normalizeLanguage((string) ($language ?: 'English'));
    }

    private function buildGreeting(SmsGroupThread $thread, bool $pendingOnly = false, string $language = 'English'): string
    {
        $greetingWord = match ($language) {
            'Spanish' => 'Hola',
            'Polish' => 'Cześć',
            default => 'Hi',
        };

        $recipientNames = $this->resolveRecipientNames($thread, $pendingOnly);

        if ($recipientNames === '') {
            return $language === 'English' ? 'Hi there,' : "{$greetingWord},";
        }

        return "{$greetingWord} {$recipientNames},";
    }

    private function resolveRecipientNames(SmsGroupThread $thread, bool $pendingOnly = false): string
    {
        $thread->loadMissing('client.users', 'subjectVendor.users', 'threadParticipants');

        $participants = collect($thread->participants ?? [])
            ->map(fn (string $phone): string => self::formatE164($phone));

        if ($pendingOnly) {
            $pendingPhones = $thread->threadParticipants
                ->filter(fn (SmsThreadParticipant $participant): bool => $participant->opted_in_at === null)
                ->pluck('phone_number')
                ->map(fn (string $phone): string => self::formatE164($phone))
                ->unique()
                ->values();

            $participants = $participants
                ->filter(fn (string $phone): bool => $pendingPhones->contains($phone))
                ->values();
        }

        $names = collect();

        $client = $thread->client;
        if ($client) {
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

                $displayFirstName = $this->resolveDisplayFirstName($user);
                if ($displayFirstName !== '') {
                    $names->push($displayFirstName);
                }
            }
        } elseif ($thread->subjectVendor) {
            // Handle vendor threads
            $vendor = $thread->subjectVendor;

            $vendorUserNames = collect();

            foreach ($vendor->users as $user) {
                $cellPhone = $user->getRawOriginal('cell_phone');
                if (! is_string($cellPhone) || $cellPhone === '') {
                    continue;
                }

                $cellPhoneE164 = self::formatE164($cellPhone);
                if (! $participants->contains($cellPhoneE164)) {
                    continue;
                }

                $displayFirstName = $this->resolveDisplayFirstName($user);
                if ($displayFirstName !== '') {
                    $vendorUserNames->push($displayFirstName);
                }
            }

            if ($vendorUserNames->isNotEmpty()) {
                return $vendorUserNames->filter()->unique()->join(', ', ' & ');
            }

            $businessPhone = $vendor->getRawOriginal('business_phone');
            if (is_string($businessPhone) && $businessPhone !== '') {
                $businessPhoneE164 = self::formatE164($businessPhone);
                if ($participants->contains($businessPhoneE164)) {
                    $names->push($vendor->short_name ?: $vendor->name);
                }
            }
        }

        return $names->filter()->unique()->join(', ', ' & ');
    }

    private function resolveDisplayFirstName(object $user): string
    {
        $nickname = trim((string) ($user->nickname ?? ''));
        if ($nickname !== '') {
            return $nickname;
        }

        return trim((string) ($user->first_name ?? ''));
    }

    /**
     * Check if a phone number belongs to us (any of our Telnyx numbers).
     */
    public static function isOurNumber(?string $phone): bool
    {
        if (! $phone) {
            return false;
        }

        $numbers = config('services.telnyx.numbers', []);

        return in_array($phone, $numbers, true);
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

<?php

namespace App\Livewire\Vendors;

use App\Models\Vendor;
use Flux;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The phone system: who inbound calls ring and in what order (vendors and
 * subs can ring their own order, or everyone at once), and every prompt and
 * text the line plays or sends. Its own page since 2026-10-16; it used to
 * be the bottom half of Options. Only the company whose Telnyx numbers the
 * line uses (vendor 1) has one.
 */
#[Title('Phone System')]
class PhoneSystemOptions extends Component
{
    use AuthorizesRequests;

    public Vendor $vendor;

    /** @var array<int> Admin user IDs selected to receive inbound calls */
    public array $call_recipients = [];

    /**
     * Ring a different order when a vendor or sub calls (Patryk, 2026-10-16:
     * Greg first, then Patryk). Off, they ring the Call Recipients order.
     */
    public bool $vendor_call_order_enabled = false;

    /** @var array<int> Admin user IDs rung, in this order, when a vendor or sub calls */
    public array $vendor_call_recipients = [];

    /** The Hive team: who sees the Team tab and can call/message teammates inside Hive (TeamRoster). */
    public array $team_member_user_ids = [];

    /** How vendors and subs ring: 'one_by_one' (the order above) or 'all' (everyone ticked at once). */
    public string $vendor_call_ring_mode = 'one_by_one';

    public bool $call_welcome_enabled = true;
    public bool $voicemail_enabled = true;
    public string $welcome_message = '';
    public string $welcome_message_unknown = '';
    public string $screening_message = '';

    /**
     * Announce the caller to whoever picks up before connecting them. Off,
     * the team member is connected the moment they answer (their phone's
     * caller ID already shows the caller's name); the prompt alone cost
     * 6–7 s of every inbound pickup (2026-09-17 log review).
     */
    public bool $screening_enabled = true;

    public string $missed_call_text = '';

    public string $inbound_callback_text = '';
    public string $voicemail_message = '';
    public string $voicemail_message_unknown = '';
    public string $ivr_press1_message = '';
    public string $ivr_press2_message = '';
    public string $voicemail_greeting = '';
    public string $spam_message = '';
    public string $after_hours_message = '';
    public string $recording_disclosure_message = '';

    /** @var \Illuminate\Support\Collection<\App\Models\User> Admin users with cell phones, who can receive calls */
    public $adminUsersWithPhones;

    public const DEFAULT_WELCOME = "{greeting} {name}! Thanks for calling {company}. One moment while we connect you.";
    public const DEFAULT_WELCOME_UNKNOWN = "{greeting}! Thanks for calling {company}. One moment while we connect you.";
    // Short on purpose: the answering team member hears it before the caller
    // is connected, so every word is dead air for both sides.
    public const DEFAULT_SCREENING = "Call from {name}.";

    /**
     * Always spoken after the screening prompt. The answerer is connected
     * once it ends and answering machine detection has heard a person; a
     * voicemail box that picks up is dropped instead, and the next person
     * rings. Short on purpose: "Stay on the line to connect, or…" made the
     * prompt 6–7 s, the most of an 8–9 s pickup (2026-10-16).
     */
    public const SCREENING_INSTRUCTION = "Press 1 to text them back.";
    public const DEFAULT_VOICEMAIL = "{company} is not available right now. {name}, if this is an emergency, press 1 to redial {company}. Press 2 to send a text on your behalf so {company} knows to call you back as soon as possible. Stay on the line to leave a voicemail.";
    public const DEFAULT_VOICEMAIL_UNKNOWN = "{company} is not available right now. Press 2 to send a text on your behalf so {company} knows to call you back as soon as possible. Stay on the line to leave a voicemail.";
    public const DEFAULT_IVR_PRESS1 = "{name}, no problem! Let me try connecting you again. I also texted you emergency numbers in case you cannot get through again.";
    public const DEFAULT_IVR_PRESS2 = "Got it! We've sent a message to {company} letting them know you called. They should be reaching out to you shortly. Take care!";
    public const DEFAULT_VOICEMAIL_GREETING = "{greeting} {name}, you've reached {company}. We can't get to the phone right now, but leave us a message after the beep and we'll get back to you shortly.";
    public const DEFAULT_SPAM = "Your call has been identified as spam and will not ring through. If this is not a spam call, please press 2 to send a text on your behalf so the {company} crew knows to call you back as soon as possible. Or stay on the line to leave a voicemail.";
    public const DEFAULT_AFTER_HOURS = "You've reached {company} after hours.";
    public const DEFAULT_RECORDING_DISCLOSURE = 'This call is recorded.';

    // Sent to the person we called when the caller presses 1 during an
    // outbound call (typically once their voicemail picks up).
    public const DEFAULT_MISSED_CALL_TEXT = '{company} tried reaching you. Please give us a call back.';

    // Sent to an inbound caller when the admin who is announced the call
    // presses 1 instead of taking it.
    public const DEFAULT_INBOUND_CALLBACK_TEXT = '{company} will call you right back.';

    public function mount(): void
    {
        $this->authorize('viewOptions', Vendor::class);

        $this->vendor = auth()->user()->vendor;
        abort_unless((int) $this->vendor?->id === 1, 404);

        $this->call_recipients = (array) data_get($this->vendor->options, 'call_recipients', []);
        $this->vendor_call_recipients = (array) data_get($this->vendor->options, 'vendor_call_recipients', []);
        $this->vendor_call_order_enabled = $this->vendor_call_recipients !== [];
        $this->vendor_call_ring_mode = data_get($this->vendor->options, 'vendor_call_ring_all') ? 'all' : 'one_by_one';
        $this->call_welcome_enabled = (bool) data_get($this->vendor->options, 'call_welcome_enabled', true);
        $this->voicemail_enabled = (bool) data_get($this->vendor->options, 'voicemail_enabled', true);
        $this->welcome_message = data_get($this->vendor->options, 'welcome_message', '') ?: self::DEFAULT_WELCOME;
        $this->welcome_message_unknown = data_get($this->vendor->options, 'welcome_message_unknown', '') ?: self::DEFAULT_WELCOME_UNKNOWN;
        $this->screening_message = data_get($this->vendor->options, 'screening_message', '') ?: self::DEFAULT_SCREENING;
        $this->screening_enabled = (bool) data_get($this->vendor->options, 'screening_enabled', true);
        $this->missed_call_text = data_get($this->vendor->options, 'missed_call_text', '') ?: self::DEFAULT_MISSED_CALL_TEXT;
        $this->inbound_callback_text = data_get($this->vendor->options, 'inbound_callback_text', '') ?: self::DEFAULT_INBOUND_CALLBACK_TEXT;
        $this->voicemail_message = data_get($this->vendor->options, 'voicemail_message', '') ?: self::DEFAULT_VOICEMAIL;
        $this->voicemail_message_unknown = data_get($this->vendor->options, 'voicemail_message_unknown', '') ?: self::DEFAULT_VOICEMAIL_UNKNOWN;
        $this->ivr_press1_message = data_get($this->vendor->options, 'ivr_press1_message', '') ?: self::DEFAULT_IVR_PRESS1;
        $this->ivr_press2_message = data_get($this->vendor->options, 'ivr_press2_message', '') ?: self::DEFAULT_IVR_PRESS2;
        $this->voicemail_greeting = data_get($this->vendor->options, 'voicemail_greeting', '') ?: self::DEFAULT_VOICEMAIL_GREETING;
        $this->spam_message = data_get($this->vendor->options, 'spam_message', '') ?: self::DEFAULT_SPAM;
        $this->after_hours_message = data_get($this->vendor->options, 'after_hours_message', '') ?: self::DEFAULT_AFTER_HOURS;
        $this->recording_disclosure_message = data_get($this->vendor->options, 'recording_disclosure_message', '') ?: self::DEFAULT_RECORDING_DISCLOSURE;

        $this->adminUsersWithPhones = $this->vendor->getAdminUsersWithCellPhones();
        $this->team_member_user_ids = array_map('strval', \App\Support\Team\TeamRoster::memberIds($this->vendor));
    }

    /**
     * Everyone employed by the company, for the Team picker.
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\User>
     */
    public function teamRows(): \Illuminate\Support\Collection
    {
        return $this->vendor->users()->wherePivot('is_employed', 1)->orderBy('users.first_name')->get();
    }

    protected function rules(): array
    {
        return [
            'call_recipients' => 'array',
            'call_recipients.*' => 'integer|exists:users,id',
            'vendor_call_order_enabled' => 'boolean',
            'vendor_call_recipients' => 'array',
            'vendor_call_recipients.*' => 'integer|exists:users,id',
            'team_member_user_ids' => 'array',
            'team_member_user_ids.*' => 'integer|exists:users,id',
            'vendor_call_ring_mode' => 'in:one_by_one,all',
            'call_welcome_enabled' => 'boolean',
            'voicemail_enabled' => 'boolean',
            'welcome_message' => 'nullable|string|max:500',
            'welcome_message_unknown' => 'nullable|string|max:500',
            'screening_message' => 'nullable|string|max:500',
            'screening_enabled' => 'boolean',
            'missed_call_text' => 'nullable|string|max:320',
            'inbound_callback_text' => 'nullable|string|max:320',
            'voicemail_message' => 'nullable|string|max:500',
            'voicemail_message_unknown' => 'nullable|string|max:500',
            'ivr_press1_message' => 'nullable|string|max:500',
            'ivr_press2_message' => 'nullable|string|max:500',
            'voicemail_greeting' => 'nullable|string|max:500',
            'spam_message' => 'nullable|string|max:500',
            'after_hours_message' => 'nullable|string|max:500',
            'recording_disclosure_message' => 'nullable|string|max:300',
        ];
    }

    /**
     * Ticked recipients in call order, limited to admins who can receive
     * calls (the list the page offers).
     *
     * @return array<int, int>
     */
    public function orderedRecipientIds(): array
    {
        return $this->orderedIds($this->call_recipients);
    }

    /**
     * Ticked people of the vendor and sub order, in call order.
     *
     * @return array<int, int>
     */
    public function orderedVendorRecipientIds(): array
    {
        return $this->orderedIds($this->vendor_call_recipients);
    }

    /**
     * @param  array<int, int|string>  $ids
     * @return array<int, int>
     */
    protected function orderedIds(array $ids): array
    {
        $offered = collect($this->adminUsersWithPhones)->pluck('id')->map(fn ($id) => (int) $id)->all();

        return array_values(array_unique(array_filter(
            array_map('intval', $ids),
            fn (int $id) => in_array($id, $offered, true)
        )));
    }

    /**
     * The admins to show in the Call Recipients list: ticked people first,
     * in the order they are called, then everyone else.
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\User>
     */
    public function recipientRows(): \Illuminate\Support\Collection
    {
        return $this->rowsInOrder($this->orderedRecipientIds());
    }

    /**
     * The same, for the vendor and sub order.
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\User>
     */
    public function vendorRecipientRows(): \Illuminate\Support\Collection
    {
        return $this->rowsInOrder($this->orderedVendorRecipientIds());
    }

    /**
     * @param  array<int, int>  $callOrder
     * @return \Illuminate\Support\Collection<int, \App\Models\User>
     */
    protected function rowsInOrder(array $callOrder): \Illuminate\Support\Collection
    {
        $order = array_flip($callOrder);

        return collect($this->adminUsersWithPhones)
            ->sortBy(fn ($user) => $order[(int) $user->id] ?? PHP_INT_MAX)
            ->values();
    }

    /**
     * Moves a ticked recipient one place earlier in the call order.
     */
    public function moveRecipientUp(int $userId): void
    {
        $this->moveRecipient($userId, -1);
    }

    /**
     * Moves a ticked recipient one place later in the call order.
     */
    public function moveRecipientDown(int $userId): void
    {
        $this->moveRecipient($userId, 1);
    }

    protected function moveRecipient(int $userId, int $offset): void
    {
        $this->call_recipients = $this->swapped($this->orderedRecipientIds(), $userId, $offset);
    }

    /**
     * Moves a ticked person one place earlier in the vendor and sub order.
     */
    public function moveVendorRecipientUp(int $userId): void
    {
        $this->vendor_call_recipients = $this->swapped($this->orderedVendorRecipientIds(), $userId, -1);
    }

    /**
     * Moves a ticked person one place later in the vendor and sub order.
     */
    public function moveVendorRecipientDown(int $userId): void
    {
        $this->vendor_call_recipients = $this->swapped($this->orderedVendorRecipientIds(), $userId, 1);
    }

    /**
     * Turning the vendor and sub order on starts it from the Call Recipients
     * order, to rearrange from there.
     */
    public function updatedVendorCallOrderEnabled(bool $enabled): void
    {
        if ($enabled && $this->orderedVendorRecipientIds() === []) {
            $this->vendor_call_recipients = $this->orderedRecipientIds();
        }
    }

    /**
     * $order with $userId moved $offset places, or unchanged past either end
     * or for someone not in it.
     *
     * @param  array<int, int>  $order
     * @return array<int, int>
     */
    protected function swapped(array $order, int $userId, int $offset): array
    {
        $index = array_search($userId, $order, true);
        $target = $index === false ? false : $index + $offset;

        if ($index === false || $target < 0 || $target >= count($order)) {
            return $order;
        }

        [$order[$index], $order[$target]] = [$order[$target], $order[$index]];

        return $order;
    }

    public function save(): void
    {
        $this->authorize('viewOptions', Vendor::class);
        $this->validate();

        $options = (array) ($this->vendor->options ?? []);
        // Stored order is the ring order: the cascade dials the first name,
        // then the next after each unanswered window. The arrows set it; a
        // newly ticked person joins at the end.
        $options['call_recipients'] = $this->orderedRecipientIds();
        // Empty = vendors and subs ring the Call Recipients order.
        $options['vendor_call_recipients'] = $this->vendor_call_order_enabled ? $this->orderedVendorRecipientIds() : [];
        $options['vendor_call_ring_all'] = $this->vendor_call_order_enabled && $this->vendor_call_ring_mode === 'all';
        $options[\App\Support\Team\TeamRoster::OPTION] = array_values(array_unique(array_map('intval', $this->team_member_user_ids)));
        $options['call_welcome_enabled'] = $this->call_welcome_enabled;
        $options['voicemail_enabled'] = $this->voicemail_enabled;
        $options['welcome_message'] = $this->welcome_message ?: null;
        $options['welcome_message_unknown'] = $this->welcome_message_unknown ?: null;
        $options['screening_message'] = $this->screening_message ?: null;
        $options['screening_enabled'] = $this->screening_enabled;
        $options['missed_call_text'] = $this->missed_call_text ?: null;
        $options['inbound_callback_text'] = $this->inbound_callback_text ?: null;
        $options['voicemail_message'] = $this->voicemail_message ?: null;
        $options['voicemail_message_unknown'] = $this->voicemail_message_unknown ?: null;
        $options['ivr_press1_message'] = $this->ivr_press1_message ?: null;
        $options['ivr_press2_message'] = $this->ivr_press2_message ?: null;
        $options['voicemail_greeting'] = $this->voicemail_greeting ?: null;
        $options['spam_message'] = $this->spam_message ?: null;
        $options['after_hours_message'] = $this->after_hours_message ?: null;
        $options['recording_disclosure_message'] = $this->recording_disclosure_message ?: null;

        $this->vendor->options = $options;
        $this->vendor->save();
        // The whole-team thread follows the roster (same thread, new members).
        \App\Support\Team\TeamRoster::syncGroupThread($this->vendor);

        Flux::toast(
            variant: 'success',
            heading: 'Phone system saved',
            text: 'Your call settings have been updated.',
        );
    }

    /**
     * Generate a TTS audio preview via the Telnyx API and return the audio data URL.
     */
    public function previewTts(string $type): void
    {
        $shortName = data_get($this->vendor->options, 'short_name') ?: ($this->vendor->business_name ?? 'our team');

        $hour = now($this->vendor->timezone ?: 'America/New_York')->hour;
        $greeting = match (true) {
            $hour < 12 => 'Good morning',
            $hour < 17 => 'Good afternoon',
            default => 'Good evening',
        };

        $template = match ($type) {
            'welcome' => $this->welcome_message ?: self::DEFAULT_WELCOME,
            'welcome_unknown' => $this->welcome_message_unknown ?: self::DEFAULT_WELCOME_UNKNOWN,
            'screening' => trim(($this->screening_message ?: self::DEFAULT_SCREENING).' '.self::SCREENING_INSTRUCTION),
            'voicemail' => $this->voicemail_message ?: self::DEFAULT_VOICEMAIL,
            'voicemail_unknown' => $this->voicemail_message_unknown ?: self::DEFAULT_VOICEMAIL_UNKNOWN,
            'ivr_press1' => $this->ivr_press1_message ?: self::DEFAULT_IVR_PRESS1,
            'ivr_press2' => $this->ivr_press2_message ?: self::DEFAULT_IVR_PRESS2,
            'voicemail_greeting' => $this->voicemail_greeting ?: self::DEFAULT_VOICEMAIL_GREETING,
            'spam' => $this->spam_message ?: self::DEFAULT_SPAM,
            'after_hours' => $this->after_hours_message ?: self::DEFAULT_AFTER_HOURS,
            'recording_disclosure' => $this->recording_disclosure_message ?: self::DEFAULT_RECORDING_DISCLOSURE,
            default => '',
        };

        $text = str_replace(
            ['{name}', '{company}', '{greeting}'],
            ['Katie', $shortName, $greeting],
            $template
        );

        // Clean up extra spaces/punctuation from empty placeholders
        $text = preg_replace('/\s+/', ' ', trim($text));
        $text = preg_replace('/\s+([!.?,])/', '$1', $text);

        // Ensure the text ends with sentence-final punctuation so TTS prosody lands naturally.
        if ($text !== '' && ! preg_match('/[.!?]$/', $text)) {
            $text .= '.';
        }

        $apiKey = config('services.telnyx.api_key');

        if (! $apiKey) {
            Flux::toast(variant: 'danger', heading: 'Not Configured', text: 'Telnyx API key is not set.', duration: 3000, position: 'top right');
            return;
        }

        $isAzure = config('services.telnyx.tts_voice_type') === 'azure';

        // Azure Neural clips the last phoneme without an explicit trailing silence.
        // Wrap in SSML with a 400 ms break — same approach used in the live call flow.
        $payload = $isAzure
            ? '<speak version="1.0" xml:lang="en-US">' . htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '<break time="400ms"/></speak>'
            : $text;

        $requestBody = [
            'voice' => config('services.telnyx.tts_voice'),
            'text' => $payload,
        ];

        if ($isAzure) {
            $requestBody['text_type'] = 'ssml';
        }

        try {
            $response = \Illuminate\Support\Facades\Http::withToken($apiKey)
                ->post('https://api.telnyx.com/v2/text-to-speech/speech', $requestBody);

            if ($response->successful()) {
                $audioBase64 = base64_encode($response->body());
                $this->dispatch('play-tts-preview', audioData: $audioBase64);
            } else {
                Flux::toast(variant: 'danger', heading: 'Preview Failed', text: 'Could not generate audio preview.', duration: 3000, position: 'top right');
            }
        } catch (\Exception $e) {
            Flux::toast(variant: 'danger', heading: 'Error', text: 'Failed to generate preview.', duration: 3000, position: 'top right');
        }
    }

    public function render()
    {
        return view('livewire.vendors.phone-system-options');
    }
}

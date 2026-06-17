<?php

namespace App\Livewire\Vendors;

use App\Models\Vendor;
use Flux;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

class VendorOptions extends Component
{
    use AuthorizesRequests, WithFileUploads;

    public Vendor $vendor;
    public string $short_name = '';
    public $logo = null;
    public ?string $existing_logo = null;
    public bool $sms_team_enabled = true;
    public bool $sms_client_enabled = true;
    public bool $sms_vendor_enabled = true;

    /** @var array<int> Admin user IDs selected to receive inbound calls */
    public array $call_recipients = [];

    /** @var array<int> Admin user IDs who must sign contracts by default */
    public array $default_contract_signers = [];

    public bool $call_welcome_enabled = true;
    public bool $voicemail_enabled = true;
    public string $welcome_message = '';
    public string $welcome_message_unknown = '';
    public string $screening_message = '';
    public string $voicemail_message = '';
    public string $voicemail_message_unknown = '';
    public string $ivr_press1_message = '';
    public string $ivr_press2_message = '';
    public string $voicemail_greeting = '';
    public string $spam_message = '';
    public string $after_hours_message = '';
    public string $recording_disclosure_message = '';

    /** Business hours — drive after-hours call routing and default user notification window. */
    public string $business_hours_start = '07:00';
    public string $business_hours_end = '18:00';
    /** @var array<int> ISO weekdays (1=Mon … 7=Sun) when business is open. */
    public array $business_hours_days = [1, 2, 3, 4, 5];

    public const DEFAULT_WELCOME = "{greeting} {name}! Thanks for calling {company}. One moment while we connect you.";
    public const DEFAULT_WELCOME_UNKNOWN = "{greeting}! Thanks for calling {company}. One moment while we connect you.";
    public const DEFAULT_SCREENING = "{name} is calling. Hang up now to send them to voicemail, or remain on the line to connect.";
    public const DEFAULT_VOICEMAIL = "{company} is not available right now. {name}, if this is an emergency, press 1 to redial {company}. Press 2 to send a text on your behalf so {company} knows to call you back as soon as possible. Stay on the line to leave a voicemail.";
    public const DEFAULT_VOICEMAIL_UNKNOWN = "{company} is not available right now. Press 2 to send a text on your behalf so {company} knows to call you back as soon as possible. Stay on the line to leave a voicemail.";
    public const DEFAULT_IVR_PRESS1 = "{name}, no problem! Let me try connecting you again. I also texted you emergency numbers in case you cannot get through again.";
    public const DEFAULT_IVR_PRESS2 = "Got it! We've sent a message to {company} letting them know you called. They should be reaching out to you shortly. Take care!";
    public const DEFAULT_VOICEMAIL_GREETING = "{greeting} {name}, you've reached {company}. We can't get to the phone right now, but leave us a message after the beep and we'll get back to you shortly.";
    public const DEFAULT_SPAM = "Your call has been identified as spam and will not ring through. If this is not a spam call, please press 2 to send a text on your behalf so the {company} crew knows to call you back as soon as possible. Or stay on the line to leave a voicemail.";
    public const DEFAULT_AFTER_HOURS = "You've reached {company} after hours.";
    public const DEFAULT_RECORDING_DISCLOSURE = 'This call is recorded.';

    /** @var \Illuminate\Support\Collection<\App\Models\User> Available admin users with cell phones */
    public $adminUsersWithPhones;

    /**
     * Derive a PHP timezone identifier from a US state abbreviation.
     */
    public static function timezoneFromState(?string $state): string
    {
        return match (strtoupper((string) $state)) {
            'CT', 'DC', 'DE', 'FL', 'GA', 'IN', 'KY', 'MA', 'MD', 'ME',
            'MI', 'NC', 'NH', 'NJ', 'NY', 'OH', 'PA', 'RI', 'SC', 'VA',
            'VT', 'WV' => 'America/New_York',
            'AL', 'AR', 'IA', 'IL', 'KS', 'LA', 'MN', 'MO', 'MS', 'ND',
            'NE', 'OK', 'SD', 'TN', 'TX', 'WI' => 'America/Chicago',
            'AZ' => 'America/Phoenix',
            'CO', 'ID', 'MT', 'NM', 'UT', 'WY' => 'America/Denver',
            'CA', 'NV', 'OR', 'WA' => 'America/Los_Angeles',
            'AK' => 'America/Anchorage',
            'HI' => 'Pacific/Honolulu',
            default => 'America/Chicago',
        };
    }

    #[Title('Options')]

    public function mount(): void
    {
        $this->authorize('viewOptions', Vendor::class);
        
        $this->vendor = auth()->user()->vendor;
        $this->short_name = $this->vendor->options?->short_name ?? '';
        $this->existing_logo = $this->vendor->options?->logo ?? null;
        $baseSmsEnabled = (bool) data_get($this->vendor->options, 'sms_enabled', true);
        $this->sms_team_enabled = (bool) data_get($this->vendor->options, 'sms_team_enabled', $baseSmsEnabled);
        $this->sms_client_enabled = (bool) data_get($this->vendor->options, 'sms_client_enabled', $baseSmsEnabled);
        $this->sms_vendor_enabled = (bool) data_get($this->vendor->options, 'sms_vendor_enabled', $baseSmsEnabled);

        // Phone system settings
        $this->call_recipients = (array) data_get($this->vendor->options, 'call_recipients', []);
        $this->default_contract_signers = (array) data_get($this->vendor->options, 'default_contract_signers', []);
        $this->call_welcome_enabled = (bool) data_get($this->vendor->options, 'call_welcome_enabled', true);
        $this->voicemail_enabled = (bool) data_get($this->vendor->options, 'voicemail_enabled', true);
        $this->welcome_message = data_get($this->vendor->options, 'welcome_message', '') ?: self::DEFAULT_WELCOME;
        $this->welcome_message_unknown = data_get($this->vendor->options, 'welcome_message_unknown', '') ?: self::DEFAULT_WELCOME_UNKNOWN;
        $this->screening_message = data_get($this->vendor->options, 'screening_message', '') ?: self::DEFAULT_SCREENING;
        $this->voicemail_message = data_get($this->vendor->options, 'voicemail_message', '') ?: self::DEFAULT_VOICEMAIL;
        $this->voicemail_message_unknown = data_get($this->vendor->options, 'voicemail_message_unknown', '') ?: self::DEFAULT_VOICEMAIL_UNKNOWN;
        $this->ivr_press1_message = data_get($this->vendor->options, 'ivr_press1_message', '') ?: self::DEFAULT_IVR_PRESS1;
        $this->ivr_press2_message = data_get($this->vendor->options, 'ivr_press2_message', '') ?: self::DEFAULT_IVR_PRESS2;
        $this->voicemail_greeting = data_get($this->vendor->options, 'voicemail_greeting', '') ?: self::DEFAULT_VOICEMAIL_GREETING;
        $this->spam_message = data_get($this->vendor->options, 'spam_message', '') ?: self::DEFAULT_SPAM;
        $this->after_hours_message = data_get($this->vendor->options, 'after_hours_message', '') ?: self::DEFAULT_AFTER_HOURS;
        $this->recording_disclosure_message = data_get($this->vendor->options, 'recording_disclosure_message', '') ?: self::DEFAULT_RECORDING_DISCLOSURE;

        $hours = $this->vendor->businessHours();
        $this->business_hours_start = $hours['start'];
        $this->business_hours_end = $hours['end'];
        $this->business_hours_days = $hours['days'];

        $this->adminUsersWithPhones = $this->vendor->getAdminUsersWithCellPhones();
    }

    protected function rules(): array
    {
        return [
            'short_name' => 'nullable|string|max:100',
            'logo' => 'nullable|image|max:10240', // 10MB max
            'sms_team_enabled' => 'boolean',
            'sms_client_enabled' => 'boolean',
            'sms_vendor_enabled' => 'boolean',
            'call_recipients' => 'array',
            'call_recipients.*' => 'integer|exists:users,id',
            'default_contract_signers' => 'array',
            'default_contract_signers.*' => 'integer|exists:users,id',
            'call_welcome_enabled' => 'boolean',
            'voicemail_enabled' => 'boolean',
            'welcome_message' => 'nullable|string|max:500',
            'welcome_message_unknown' => 'nullable|string|max:500',
            'screening_message' => 'nullable|string|max:500',
            'voicemail_message' => 'nullable|string|max:500',
            'voicemail_message_unknown' => 'nullable|string|max:500',
            'ivr_press1_message' => 'nullable|string|max:500',
            'ivr_press2_message' => 'nullable|string|max:500',
            'voicemail_greeting' => 'nullable|string|max:500',
            'spam_message' => 'nullable|string|max:500',
            'after_hours_message' => 'nullable|string|max:500',
            'recording_disclosure_message' => 'nullable|string|max:300',
            'business_hours_start' => 'required|date_format:H:i',
            'business_hours_end' => 'required|date_format:H:i|after:business_hours_start',
            'business_hours_days' => 'array',
            'business_hours_days.*' => 'integer|between:1,7',
        ];
    }

    public function save(): void
    {
        $this->authorize('viewOptions', Vendor::class);
        $this->validate();

        // Update timezone derived from vendor address state
        $this->vendor->timezone = self::timezoneFromState($this->vendor->state) ?: null;

        // Build options object
        $options = (array) ($this->vendor->options ?? []);
        $options['short_name'] = $this->short_name ?: null;
        $options['sms_team_enabled'] = $this->sms_team_enabled;
        $options['sms_client_enabled'] = $this->sms_client_enabled;
        $options['sms_vendor_enabled'] = $this->sms_vendor_enabled;
        $options['sms_enabled'] = $this->sms_team_enabled && $this->sms_client_enabled && $this->sms_vendor_enabled;

        // Phone system settings
        $options['call_recipients'] = array_map('intval', $this->call_recipients);
        $options['default_contract_signers'] = array_map('intval', $this->default_contract_signers);
        $options['call_welcome_enabled'] = $this->call_welcome_enabled;
        $options['voicemail_enabled'] = $this->voicemail_enabled;
        $options['welcome_message'] = $this->welcome_message ?: null;
        $options['welcome_message_unknown'] = $this->welcome_message_unknown ?: null;
        $options['screening_message'] = $this->screening_message ?: null;
        $options['voicemail_message'] = $this->voicemail_message ?: null;
        $options['voicemail_message_unknown'] = $this->voicemail_message_unknown ?: null;
        $options['ivr_press1_message'] = $this->ivr_press1_message ?: null;
        $options['ivr_press2_message'] = $this->ivr_press2_message ?: null;
        $options['voicemail_greeting'] = $this->voicemail_greeting ?: null;
        $options['spam_message'] = $this->spam_message ?: null;
        $options['after_hours_message'] = $this->after_hours_message ?: null;
        $options['recording_disclosure_message'] = $this->recording_disclosure_message ?: null;
        $options['business_hours_start'] = $this->business_hours_start;
        $options['business_hours_end'] = $this->business_hours_end;
        $options['business_hours_days'] = array_values(array_map('intval', $this->business_hours_days));

        // Handle logo upload
        if ($this->logo) {
            // Delete old logo if exists
            if ($this->existing_logo && Storage::disk('public')->exists($this->existing_logo)) {
                Storage::disk('public')->delete($this->existing_logo);
            }

            // Store new logo
            $path = $this->logo->store('vendor-logos', 'public');
            $options['logo'] = $path;
            $this->existing_logo = $path;
        }

        $this->vendor->options = $options;
        $this->vendor->save();

        $this->reset('logo');

        Flux::toast(
            variant: 'success',
            heading: 'Options saved',
            text: 'Your vendor options have been updated.',
        );
    }

    public function removeLogo(): void
    {
        $this->authorize('viewOptions', Vendor::class);

        if ($this->existing_logo && Storage::disk('public')->exists($this->existing_logo)) {
            Storage::disk('public')->delete($this->existing_logo);
        }

        $options = (array) ($this->vendor->options ?? []);
        unset($options['logo']);
        $this->vendor->options = $options ?: null;
        $this->vendor->save();

        $this->existing_logo = null;

        Flux::toast(
            variant: 'success',
            heading: 'Logo removed',
            text: 'Your business logo has been removed.',
        );
    }

    public function removePendingLogo(): void
    {
        $this->authorize('viewOptions', Vendor::class);

        if ($this->logo) {
            $this->logo->delete();
        }

        $this->logo = null;
    }

    /**
     * Generate a TTS audio preview via the Telnyx API and return the audio data URL.
     */
    public function previewTts(string $type): void
    {
        $shortName = $this->short_name ?: ($this->vendor->business_name ?? 'our team');

        $hour = now($this->vendor->timezone ?: 'America/New_York')->hour;
        $greeting = match (true) {
            $hour < 12 => 'Good morning',
            $hour < 17 => 'Good afternoon',
            default => 'Good evening',
        };

        $template = match ($type) {
            'welcome' => $this->welcome_message ?: self::DEFAULT_WELCOME,
            'welcome_unknown' => $this->welcome_message_unknown ?: self::DEFAULT_WELCOME_UNKNOWN,
            'screening' => $this->screening_message ?: self::DEFAULT_SCREENING,
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
        return view('livewire.vendors.vendor-options');
    }
}

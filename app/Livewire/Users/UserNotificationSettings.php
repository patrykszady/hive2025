<?php

namespace App\Livewire\Users;

use App\Models\NotificationSetting;
use App\Models\PushToken;
use App\Models\User;
use Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class UserNotificationSettings extends Component
{
    use AuthorizesRequests;

    public User $user;

    // Per-type channel toggles. Realtime SMS has no property: it is forced
    // on in save() and rendered as a disabled-checked switch — a task change
    // must always be able to reach the crew by text.
    public bool $realtime_email = true;
    public bool $morning_email = false;
    public bool $morning_sms = false;
    public bool $evening_email = false;
    public bool $evening_sms = false;

    // Time window
    public string $realtime_start = '07:00';
    public string $realtime_end = '18:00';

    // Hive Contractors app — the same four switches a browser subscription
    // has (PushToken::PREFERENCES), applied to every phone the user has
    // signed in on; the per-phone switch in $appDevices is the master. Read
    // from and written to push_tokens, never notification_settings.
    public bool $app_realtime = false;
    public bool $app_morning = false;
    public bool $app_evening = false;
    public bool $app_text_activity = false;

    /** @var list<array{id: int, label: string, platform: string, last_seen: ?string, enabled: bool}> */
    public array $appDevices = [];

    /** @var array<string, string> Component property → push_tokens column. */
    private const APP_PREFERENCE_COLUMNS = [
        'app_realtime' => 'realtime_enabled',
        'app_morning' => 'morning_enabled',
        'app_evening' => 'evening_enabled',
        'app_text_activity' => 'sms_inbound_enabled',
    ];

    public function mount(User $user): void
    {
        abort_unless(auth()->id() === $user->id, 403);

        $this->user = $user;

        // Seed defaults from vendor business hours so each user inherits the
        // company-wide window unless they override it.
        $vendorHours = $this->user->vendor?->businessHours();
        if ($vendorHours) {
            $this->realtime_start = $vendorHours['start'];
            $this->realtime_end = $vendorHours['end'];
        }

        $setting = $this->user->notificationSetting;

        if ($setting) {
            $this->realtime_email = (bool) $setting->realtime_email;
            $this->morning_email = (bool) $setting->morning_email;
            $this->morning_sms = (bool) $setting->morning_sms;
            $this->evening_email = (bool) $setting->evening_email;
            $this->evening_sms = (bool) $setting->evening_sms;

            $this->realtime_start = $setting->realtime_start ?? $this->realtime_start;
            $this->realtime_end = $setting->realtime_end ?? $this->realtime_end;
        }

        $this->loadAppDevices();
    }

    /**
     * Auto-save whenever any property is updated via wire:model.live. The
     * app switches have their own savers because they write push_tokens,
     * not the notification_settings row save() owns.
     */
    public function updated(string $property): void
    {
        if (isset(self::APP_PREFERENCE_COLUMNS[$property])) {
            $this->saveAppPreference($property);

            return;
        }

        if (str_starts_with($property, 'appDevices.')) {
            $this->saveAppDeviceSwitch($property);

            return;
        }

        if (in_array($property, ['realtime_start', 'realtime_end'], true)) {
            $this->normalizeRealtimeTimes();
        }

        $this->save();
    }

    protected function rules(): array
    {
        return [
            'realtime_email' => 'boolean',
            'morning_email' => 'boolean',
            'morning_sms' => 'boolean',
            'evening_email' => 'boolean',
            'evening_sms' => 'boolean',
            'realtime_start' => 'required|date_format:H:i',
            'realtime_end' => 'required|date_format:H:i|after:realtime_start',
            'app_realtime' => 'boolean',
            'app_morning' => 'boolean',
            'app_evening' => 'boolean',
            'app_text_activity' => 'boolean',
            'appDevices.*.enabled' => 'boolean',
        ];
    }

    public function save(): void
    {
        abort_unless(auth()->id() === $this->user->id, 403);
        $this->normalizeRealtimeTimes();
        $this->validate();

        $this->user->notificationSetting()->updateOrCreate(
            ['user_id' => $this->user->id],
            [
                'realtime_email' => $this->realtime_email,
                'realtime_sms' => true,
                'morning_email' => $this->morning_email,
                'morning_sms' => $this->morning_sms,
                'evening_email' => $this->evening_email,
                'evening_sms' => $this->evening_sms,
                'realtime_start' => $this->realtime_start,
                'realtime_end' => $this->realtime_end,
            ]
        );

        $this->toastSaved('Your preferences have been updated.');
    }

    public function render(): View
    {
        return view('livewire.users.notification-settings');
    }

    /**
     * One "App" switch writes the same column on every phone, the way the
     * Browser switch writes the one subscription this browser has — a user
     * with two phones expects them to agree.
     */
    protected function saveAppPreference(string $property): void
    {
        abort_unless(auth()->id() === $this->user->id, 403);
        $this->validateOnly($property);

        $this->user->pushTokens()->update([
            self::APP_PREFERENCE_COLUMNS[$property] => $this->{$property},
        ]);

        $this->loadAppDevices();
        $this->toastSaved('Your preferences have been updated.');
    }

    /** The per-phone master switch: "appDevices.{index}.enabled" → that row's `enabled`. */
    protected function saveAppDeviceSwitch(string $property): void
    {
        abort_unless(auth()->id() === $this->user->id, 403);

        [, $index, $field] = array_pad(explode('.', $property, 3), 3, null);
        $device = $this->appDevices[(int) $index] ?? null;

        if ($field !== 'enabled' || $device === null) {
            return;
        }

        $this->user->pushTokens()
            ->whereKey($device['id'])
            ->update(['enabled' => (bool) $device['enabled']]);

        $this->loadAppDevices();
        $this->toastSaved($device['enabled']
            ? "Push notifications on for {$device['label']}."
            : "Push notifications off for {$device['label']}.");
    }

    protected function loadAppDevices(): void
    {
        $devices = $this->user->pushTokens()
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->get();

        $this->appDevices = $devices->map(fn (PushToken $device) => [
            'id' => $device->id,
            'label' => $device->displayName(),
            'platform' => $device->platform,
            'last_seen' => $device->last_seen_at?->diffForHumans(),
            'enabled' => (bool) $device->enabled,
        ])->values()->all();

        // A user-level switch reads "on" when any phone has it on. The
        // phones are written in lockstep by saveAppPreference(), so they
        // only disagree when a reinstall inherited older settings.
        foreach (self::APP_PREFERENCE_COLUMNS as $property => $column) {
            $this->{$property} = $devices->contains(fn (PushToken $device) => (bool) $device->{$column});
        }
    }

    protected function toastSaved(string $text): void
    {
        Flux::toast(
            variant: 'success',
            heading: 'Notification settings saved',
            text: $text,
        );
    }

    protected function normalizeRealtimeTimes(): void
    {
        $this->realtime_start = $this->normalizeRealtimeTime($this->realtime_start);
        $this->realtime_end = $this->normalizeRealtimeTime($this->realtime_end);
    }

    /**
     * Delegates to App\Services\Users\NotificationTimeNormalizer, the
     * service shared with the Hive Contractors app's notification-settings
     * endpoint.
     */
    protected function normalizeRealtimeTime(string $value): string
    {
        return \App\Services\Users\NotificationTimeNormalizer::normalize($value);
    }
}

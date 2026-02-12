<?php

namespace App\Livewire\Users;

use App\Models\NotificationSetting;
use App\Models\User;
use Carbon\Carbon;
use Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class UserNotificationSettings extends Component
{
    use AuthorizesRequests;

    public User $user;

    // Channel toggles (master on/off per delivery method)
    public bool $channel_email = true;
    public bool $channel_sms = false;
    public bool $sms_inbound_browser = false;

    // Time window
    public string $realtime_start = '07:00';
    public string $realtime_end = '18:00';

    // Type toggles (what gets sent)
    public bool $type_realtime = true;
    public bool $type_morning = false;
    public bool $type_evening = false;

    public function mount(User $user): void
    {
        abort_unless(auth()->id() === $user->id, 403);

        $this->user = $user;

        $setting = $this->user->notificationSetting;

        if ($setting) {
            // Channel is on if any type uses it
            $this->channel_email = $setting->realtime_email || $setting->morning_email || $setting->evening_email;
            $this->channel_sms = $setting->realtime_sms || $setting->morning_sms || $setting->evening_sms;

            $this->realtime_start = $setting->realtime_start ?? '07:00';
            $this->realtime_end = $setting->realtime_end ?? '18:00';

            // Type is on if any channel uses it
            $this->type_realtime = $setting->realtime_email || $setting->realtime_sms;
            $this->type_morning = $setting->morning_email || $setting->morning_sms;
            $this->type_evening = $setting->evening_email || $setting->evening_sms;

            if ($this->user->vendor_role === 'Admin') {
                $this->sms_inbound_browser = (bool) $setting->sms_inbound_browser;
            }
        }
    }

    /**
     * Auto-save whenever any property is updated via wire:model.live.
     */
    public function updated(string $property): void
    {
        if (in_array($property, ['realtime_start', 'realtime_end'], true)) {
            $this->normalizeRealtimeTimes();
        }

        $this->save();
    }

    protected function rules(): array
    {
        return [
            'channel_email' => 'boolean',
            'channel_sms' => 'boolean',
            'sms_inbound_browser' => 'boolean',
            'realtime_start' => 'required|date_format:H:i',
            'realtime_end' => 'required|date_format:H:i|after:realtime_start',
            'type_realtime' => 'boolean',
            'type_morning' => 'boolean',
            'type_evening' => 'boolean',
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
                'realtime_email' => $this->channel_email && $this->type_realtime,
                'realtime_sms' => $this->channel_sms && $this->type_realtime,
                'sms_inbound_browser' => $this->user->vendor_role === 'Admin' && $this->sms_inbound_browser,
                'realtime_start' => $this->realtime_start,
                'realtime_end' => $this->realtime_end,
                'morning_email' => $this->channel_email && $this->type_morning,
                'morning_sms' => $this->channel_sms && $this->type_morning,
                'evening_email' => $this->channel_email && $this->type_evening,
                'evening_sms' => $this->channel_sms && $this->type_evening,
            ]
        );

        Flux::toast(
            variant: 'success',
            heading: 'Notification settings saved',
            text: 'Your preferences have been updated.',
        );
    }

    public function render(): View
    {
        return view('livewire.users.notification-settings');
    }

    protected function normalizeRealtimeTimes(): void
    {
        $this->realtime_start = $this->normalizeRealtimeTime($this->realtime_start);
        $this->realtime_end = $this->normalizeRealtimeTime($this->realtime_end);
    }

    protected function normalizeRealtimeTime(string $value): string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return $trimmed;
        }

        $formats = [
            'H:i',
            'H:i:s',
            'g:i A',
            'g:iA',
            'g:i:s A',
            'g:i:sA',
        ];

        foreach ($formats as $format) {
            try {
                return Carbon::createFromFormat($format, strtoupper($trimmed))->format('H:i');
            } catch (\Exception $e) {
                continue;
            }
        }

        return $trimmed;
    }
}

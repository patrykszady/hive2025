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

    // Per-type channel toggles
    public bool $realtime_email = true;
    public bool $realtime_sms = true;
    public bool $morning_email = false;
    public bool $morning_sms = false;
    public bool $evening_email = false;
    public bool $evening_sms = false;

    // Browser (managed via Alpine/JS push subscription)
    public bool $sms_inbound_browser = false;

    // Time window
    public string $realtime_start = '07:00';
    public string $realtime_end = '18:00';

    public function mount(User $user): void
    {
        abort_unless(auth()->id() === $user->id, 403);

        $this->user = $user;

        $setting = $this->user->notificationSetting;

        if ($setting) {
            $this->realtime_email = (bool) $setting->realtime_email;
            $this->realtime_sms = (bool) $setting->realtime_sms;
            $this->morning_email = (bool) $setting->morning_email;
            $this->morning_sms = (bool) $setting->morning_sms;
            $this->evening_email = (bool) $setting->evening_email;
            $this->evening_sms = (bool) $setting->evening_sms;

            $this->realtime_start = $setting->realtime_start ?? '07:00';
            $this->realtime_end = $setting->realtime_end ?? '18:00';

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
            'realtime_email' => 'boolean',
            'realtime_sms' => 'boolean',
            'morning_email' => 'boolean',
            'morning_sms' => 'boolean',
            'evening_email' => 'boolean',
            'evening_sms' => 'boolean',
            'sms_inbound_browser' => 'boolean',
            'realtime_start' => 'required|date_format:H:i',
            'realtime_end' => 'required|date_format:H:i|after:realtime_start',
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
                'sms_inbound_browser' => $this->user->vendor_role === 'Admin' && $this->sms_inbound_browser,
                'realtime_start' => $this->realtime_start,
                'realtime_end' => $this->realtime_end,
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

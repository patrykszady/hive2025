<?php

namespace App\Channels;

use App\Notifications\ClientScheduleSmsNotification;
use App\Notifications\TeamTaskSmsNotification;
use App\Notifications\VendorAvailabilitySmsNotification;
use App\Notifications\VendorScheduleSmsNotification;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Twilio\Rest\Client;
use App\Support\ApiErrorFormatter;

class TwilioChannel
{
    protected $twilio;

    public function __construct()
    {
        $this->twilio = new Client(
            config('services.twilio.sid'),
            config('services.twilio.token')
        );
    }

    /**
     * Send the given notification.
     */
    public function send($notifiable, Notification $notification)
    {
        $phone = $notifiable->routeNotificationFor('twilio');
        $isVendorSms = $notification instanceof VendorAvailabilitySmsNotification
            || $notification instanceof VendorScheduleSmsNotification;
        $isClientScheduleSms = $notification instanceof ClientScheduleSmsNotification;
        $isTeamTaskSms = $notification instanceof TeamTaskSmsNotification;

        $logChannel = match (true) {
            $isVendorSms => 'vendor_sms',
            $isClientScheduleSms => 'client_sms',
            $isTeamTaskSms => 'team_sms',
            default => null,
        };

        if (! $this->smsEnabledFor($notification, $notifiable)) {
            return;
        }

        if (
            app()->environment(['local', 'development'])
            && ($notification instanceof TeamTaskSmsNotification
                || $notification instanceof VendorAvailabilitySmsNotification
                || $notification instanceof VendorScheduleSmsNotification
                || $notification instanceof ClientScheduleSmsNotification)
        ) {
            $originalPhone = $phone;
            $phone = config('services.twilio.dev_to', '+12249993880');
            
            if (is_string($logChannel)) {
                Log::channel($logChannel)->info('Dev environment: redirecting SMS', [
                    'original_phone' => $originalPhone,
                    'redirected_to' => $phone,
                    'notifiable_type' => get_class($notifiable),
                    'notifiable_id' => $notifiable->id ?? null,
                    'notification' => get_class($notification),
                ]);
            }
        }

        if (!$phone) {
            if (is_string($logChannel)) {
                Log::channel($logChannel)->warning('No phone number available, skipping SMS', [
                    'notifiable_type' => get_class($notifiable),
                    'notifiable_id' => $notifiable->id ?? null,
                    'notification' => get_class($notification),
                ]);
            }
            return;
        }

        $message = $notification->toTwilio($notifiable);

        if (is_string($logChannel)) {
            Log::channel($logChannel)->debug('SMS message content', [
                'message' => $message,
                'notifiable_type' => get_class($notifiable),
                'notifiable_id' => $notifiable->id ?? null,
                'notification' => get_class($notification),
            ]);
        }

        try {
            $result = $this->twilio->messages->create(
                $phone,
                [
                    'from' => config('services.twilio.from'),
                    'body' => $message
                ]
            );
            
            if (is_string($logChannel)) {
                Log::channel($logChannel)->info('SMS sent', [
                    'phone' => $phone,
                    'twilio_sid' => $result->sid ?? null,
                    'notification' => class_basename($notification),
                ]);
            }
        } catch (\Exception $e) {
            if (is_string($logChannel)) {
                Log::channel($logChannel)->error('SMS failed to send', ApiErrorFormatter::format($e, [
                    'notifiable_type' => get_class($notifiable),
                    'notifiable_id' => $notifiable->id ?? null,
                    'phone' => $phone,
                    'notification' => get_class($notification),
                ]));
            }

            throw $e;
        }
    }

    /**
     * Check if SMS is enabled for the vendor that owns the project/task.
     *
     * We always check the "owning" vendor (project->createdByVendor) rather than
     * the recipient vendor. If GS Construction (vendor 1) has SMS disabled, no
     * SMS should go out for their projects—not to clients, team members, or subcontractors.
     */
    private function smsEnabledFor(Notification $notification, $notifiable): bool
    {
        $owningVendor = $this->resolveOwningVendor($notification, $notifiable);

        if (! $owningVendor) {
            return true;
        }

        $optionKey = $this->smsOptionKeyFor($notification);

        if (! $optionKey) {
            return true;
        }
        $defaultEnabled = (bool) data_get($owningVendor->options, 'sms_enabled', true);

        return (bool) data_get($owningVendor->options, $optionKey, $defaultEnabled);
    }

    /**
     * Resolve the vendor that owns the project/task (the one sending SMS).
     */
    private function resolveOwningVendor(Notification $notification, $notifiable): ?\App\Models\Vendor
    {
        if ($notification instanceof ClientScheduleSmsNotification) {
            return $notification->project->createdByVendor;
        }

        if ($notification instanceof VendorScheduleSmsNotification) {
            // Get the owning vendor from the first task's project
            $task = $notification->tasks->first();

            return $task?->project?->createdByVendor;
        }

        if ($notification instanceof VendorAvailabilitySmsNotification) {
            $tasks = $notification->tasks;
            $task = $tasks instanceof \App\Models\Task ? $tasks : $tasks->first();

            return $task?->project?->createdByVendor;
        }

        if ($notification instanceof TeamTaskSmsNotification) {
            // Team members work for the owning vendor - get from first task's project
            $tasks = $notification->getTasks();
            $task = is_array($tasks) ? ($tasks[0] ?? null) : $tasks->first();

            return $task?->project?->createdByVendor;
        }

        return null;
    }

    private function smsOptionKeyFor(Notification $notification): ?string
    {
        if ($notification instanceof ClientScheduleSmsNotification) {
            return 'sms_client_enabled';
        }

        if ($notification instanceof VendorScheduleSmsNotification
            || $notification instanceof VendorAvailabilitySmsNotification
        ) {
            return 'sms_vendor_enabled';
        }

        if ($notification instanceof TeamTaskSmsNotification) {
            return 'sms_team_enabled';
        }

        return null;
    }
}

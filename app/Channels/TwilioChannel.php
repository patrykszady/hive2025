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
            if (is_string($logChannel)) {
                Log::channel($logChannel)->info('SMS disabled in vendor options, skipping', [
                    'notifiable_type' => get_class($notifiable),
                    'notifiable_id' => $notifiable->id ?? null,
                    'notification' => get_class($notification),
                ]);
            }

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

    private function smsEnabledFor(Notification $notification, $notifiable): bool
    {
        $vendor = null;

        if ($notification instanceof ClientScheduleSmsNotification) {
            $vendor = $notification->project->createdByVendor;
        } elseif ($notification instanceof VendorScheduleSmsNotification) {
            $vendor = $notification->vendor;
        } elseif ($notification instanceof VendorAvailabilitySmsNotification) {
            $tasks = $notification->tasks;
            if ($tasks instanceof \App\Models\Task) {
                $vendor = $tasks->vendor;
            } else {
                $vendor = $tasks->first()?->vendor;
            }
        } elseif ($notification instanceof TeamTaskSmsNotification) {
            $vendor = $notifiable->vendor ?? null;
        }

        if (! $vendor) {
            return true;
        }

        return (bool) data_get($vendor->options, 'sms_enabled', true);
    }
}

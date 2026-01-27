<?php

namespace App\Channels;

use App\Notifications\ClientScheduleSmsNotification;
use App\Notifications\TeamTaskSmsNotification;
use App\Notifications\VendorAvailabilitySmsNotification;
use App\Notifications\VendorScheduleSmsNotification;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Support\ApiErrorFormatter;

class TelnyxChannel
{
    protected string $apiKey;
    protected string $from;
    protected ?string $messagingProfileId;

    public function __construct()
    {
        $this->apiKey = config('services.telnyx.api_key');
        $this->from = config('services.telnyx.from');
        $this->messagingProfileId = config('services.telnyx.messaging_profile_id');
    }

    /**
     * Send the given notification.
     */
    public function send($notifiable, Notification $notification)
    {
        $phone = $notifiable->routeNotificationFor('twilio') 
            ?? $notifiable->routeNotificationFor('telnyx');
            
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
            $phone = config('services.telnyx.dev_to', '+12249993880');
            
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

        // Get message - support both toTelnyx and toTwilio methods
        $message = method_exists($notification, 'toTelnyx') 
            ? $notification->toTelnyx($notifiable) 
            : $notification->toTwilio($notifiable);

        if (is_string($logChannel)) {
            Log::channel($logChannel)->debug('SMS message content', [
                'message' => $message,
                'notifiable_type' => get_class($notifiable),
                'notifiable_id' => $notifiable->id ?? null,
                'notification' => get_class($notification),
            ]);
        }

        try {
            $payload = [
                'from' => $this->from,
                'to' => $phone,
                'text' => $message,
            ];

            if ($this->messagingProfileId) {
                $payload['messaging_profile_id'] = $this->messagingProfileId;
            }

            $response = Http::withToken($this->apiKey)
                ->post('https://api.telnyx.com/v2/messages', $payload);

            if ($response->failed()) {
                $error = $response->json();
                
                if (is_string($logChannel)) {
                    Log::channel($logChannel)->error('Telnyx SMS failed', [
                        'phone' => $phone,
                        'status' => $response->status(),
                        'error' => $error,
                        'notification' => class_basename($notification),
                    ]);
                }

                throw new \Exception('Telnyx API error: ' . json_encode($error));
            }

            $result = $response->json('data');
            
            if (is_string($logChannel)) {
                Log::channel($logChannel)->info('SMS sent via Telnyx', [
                    'phone' => $phone,
                    'telnyx_id' => $result['id'] ?? null,
                    'notification' => class_basename($notification),
                ]);
            }

            return $result;
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
     * Check if SMS is enabled for the owning vendor.
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
            $task = $notification->tasks->first();

            return $task?->project?->createdByVendor;
        }

        if ($notification instanceof VendorAvailabilitySmsNotification) {
            $tasks = $notification->tasks;
            $task = $tasks instanceof \App\Models\Task ? $tasks : $tasks->first();

            return $task?->project?->createdByVendor;
        }

        if ($notification instanceof TeamTaskSmsNotification) {
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

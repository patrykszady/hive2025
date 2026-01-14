<?php

namespace App\Channels;

use App\Notifications\ClientScheduleNotification;
use App\Notifications\TaskReminderNotification;
use App\Notifications\TaskUpdateNotification;
use App\Notifications\VendorAvailabilityNotification;
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
        $isVendorSms = $notification instanceof VendorAvailabilityNotification;
        $isClientScheduleSms = $notification instanceof ClientScheduleNotification;
        $logChannel = $isVendorSms ? 'vendor_sms' : 'task_reminder';

        if (
            app()->environment(['local', 'development'])
            && ($notification instanceof TaskReminderNotification 
                || $notification instanceof TaskUpdateNotification
                || $notification instanceof VendorAvailabilityNotification
                || $notification instanceof ClientScheduleNotification)
        ) {
            $originalPhone = $phone;
            $phone = config('services.twilio.dev_to', '+12249993880');
            
            if ($isVendorSms || $isClientScheduleSms) {
                Log::channel($logChannel)->info("Dev environment: redirecting SMS", [
                    'original_phone' => $originalPhone,
                    'redirected_to' => $phone,
                    'notifiable_type' => get_class($notifiable),
                    'notifiable_id' => $notifiable->id ?? null,
                ]);
            }
        }

        if (!$phone) {
            if ($isVendorSms || $isClientScheduleSms) {
                Log::channel($logChannel)->warning("No phone number available, skipping SMS", [
                    'notifiable_type' => get_class($notifiable),
                    'notifiable_id' => $notifiable->id ?? null,
                ]);
            }
            return;
        }

        $message = $notification->toTwilio($notifiable);

        if ($isVendorSms || $isClientScheduleSms) {
            Log::channel($logChannel)->info("Sending SMS via Twilio", [
                'phone' => $phone,
                'message_length' => strlen($message),
                'from' => config('services.twilio.from'),
                'notifiable_type' => get_class($notifiable),
                'notifiable_id' => $notifiable->id ?? null,
            ]);
            Log::channel($logChannel)->debug("SMS message content", [
                'message' => $message,
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
            
            if ($isVendorSms) {
                Log::channel($logChannel)->info("SMS sent successfully", [
                    'phone' => $phone,
                    'twilio_sid' => $result->sid ?? null,
                    'status' => $result->status ?? null,
                ]);
            }
        } catch (\Exception $e) {
            Log::channel($logChannel)->error('SMS failed to send', ApiErrorFormatter::format($e, [
                'notifiable_type' => get_class($notifiable),
                'notifiable_id' => $notifiable->id ?? null,
                'phone' => $phone,
            ]));

            throw $e;
        }
    }
}

<?php

namespace App\Channels;

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

        if (
            app()->environment(['local', 'development'])
            && ($notification instanceof TaskReminderNotification 
                || $notification instanceof TaskUpdateNotification
                || $notification instanceof VendorAvailabilityNotification)
        ) {
            $phone = config('services.twilio.dev_to', '+12249993880');
        }

        if (!$phone) {
            return;
        }

        $message = $notification->toTwilio($notifiable);

        try {
            $this->twilio->messages->create(
                $phone,
                [
                    'from' => config('services.twilio.from'),
                    'body' => $message
                ]
            );
        } catch (\Exception $e) {
            Log::channel('task_reminder')->error('SMS failed to send', ApiErrorFormatter::format($e, [
                'user_id' => $notifiable->id,
                'phone' => $phone,
            ]));

            throw $e;
        }
    }
}

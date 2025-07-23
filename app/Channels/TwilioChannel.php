<?php

namespace App\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Twilio\Rest\Client;

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
            Log::channel('task_reminder')->error("SMS failed to send", [
                'user_id' => $notifiable->id,
                'phone' => $phone,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }
}

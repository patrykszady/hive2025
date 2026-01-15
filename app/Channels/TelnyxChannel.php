<?php

namespace App\Channels;

use App\Notifications\ClientScheduleSmsNotification;
use App\Services\TelnyxService;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class TelnyxChannel
{
    public function __construct(protected TelnyxService $telnyx) {}

    /**
     * Send the given notification via Telnyx.
     *
     * This channel supports group SMS/MMS - sending to multiple recipients who will
     * all be in the same conversation thread. Perfect for client notifications
     * where multiple users on a project should see the same message.
     *
     * @param  mixed  $notifiable  The entity being notified (e.g., Project, Client)
     * @param  Notification  $notification  The notification instance
     */
    public function send($notifiable, Notification $notification)
    {
        if (! $this->telnyx->isConfigured()) {
            Log::channel('telnyx')->warning('Telnyx not configured, skipping SMS');

            return;
        }

        // Check if SMS is enabled for the owning vendor
        if (! $this->smsEnabledFor($notification, $notifiable)) {
            return;
        }

        // Get recipients - can be a single phone or array of phones for group SMS
        $recipients = $this->getRecipients($notifiable, $notification);

        if (empty($recipients)) {
            Log::channel('telnyx')->warning('No recipients for Telnyx SMS', [
                'notifiable_type' => get_class($notifiable),
                'notifiable_id' => $notifiable->id ?? null,
                'notification' => get_class($notification),
            ]);

            return;
        }

        // Get the message content
        $message = $notification->toTelnyx($notifiable);

        // Check if we have media attachments (MMS)
        $mediaUrls = method_exists($notification, 'getTelnyxMediaUrls')
            ? $notification->getTelnyxMediaUrls($notifiable)
            : [];

        // Redirect in dev/local environments
        if (app()->environment(['local', 'development'])) {
            $devTo = config('services.telnyx.dev_to');
            if ($devTo) {
                Log::channel('telnyx')->info('Dev environment: redirecting SMS', [
                    'original_recipients' => $recipients,
                    'redirected_to' => $devTo,
                ]);
                $recipients = [$devTo];
            }
        }

        Log::channel('telnyx')->debug('Sending SMS', [
            'recipient_count' => count($recipients),
            'message_length' => strlen($message),
            'has_media' => ! empty($mediaUrls),
            'is_group' => count($recipients) > 1,
        ]);

        try {
            $result = $this->telnyx->sendSms($recipients, $message, null, $mediaUrls);

            Log::channel('telnyx')->info('SMS sent via Telnyx', [
                'message_id' => $result['id'] ?? null,
                'recipient_count' => count($recipients),
                'notification' => class_basename($notification),
            ]);

            return $result;
        } catch (\Exception $e) {
            Log::channel('telnyx')->error('Telnyx SMS failed', [
                'error' => $e->getMessage(),
                'notifiable_type' => get_class($notifiable),
                'notifiable_id' => $notifiable->id ?? null,
                'notification' => get_class($notification),
            ]);

            throw $e;
        }
    }

    /**
     * Get the recipient phone numbers for this notification.
     *
     * @return array<string> Phone numbers in E.164 format
     */
    protected function getRecipients($notifiable, Notification $notification): array
    {
        // First check if notification defines its own recipients
        if (method_exists($notification, 'getTelnyxRecipients')) {
            return $notification->getTelnyxRecipients($notifiable);
        }

        // Check for routeNotificationForTelnyx on notifiable
        if (method_exists($notifiable, 'routeNotificationForTelnyx')) {
            $route = $notifiable->routeNotificationForTelnyx($notification);

            return is_array($route) ? $route : [$route];
        }

        // Fallback to standard routing
        $route = $notifiable->routeNotificationFor('telnyx', $notification);

        if ($route) {
            return is_array($route) ? $route : [$route];
        }

        return [];
    }

    /**
     * Check if SMS is enabled for the vendor that owns the project/task.
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
     * Resolve the owning vendor from the notification or notifiable.
     */
    private function resolveOwningVendor(Notification $notification, $notifiable)
    {
        // Check if notification has a project property
        if (property_exists($notification, 'project') && $notification->project) {
            return $notification->project->createdByVendor ?? null;
        }

        // Check if notifiable is a project
        if (method_exists($notifiable, 'createdByVendor')) {
            return $notifiable->createdByVendor;
        }

        // Check if notifiable has vendor relationship
        if (method_exists($notifiable, 'vendor')) {
            return $notifiable->vendor;
        }

        return null;
    }

    /**
     * Get the SMS option key for the notification type.
     */
    private function smsOptionKeyFor(Notification $notification): ?string
    {
        return match (true) {
            $notification instanceof ClientScheduleSmsNotification => 'sms_schedule_enabled',
            str_contains(get_class($notification), 'Schedule') => 'sms_schedule_enabled',
            str_contains(get_class($notification), 'Reminder') => 'sms_reminder_enabled',
            default => null,
        };
    }
}

<?php

namespace App\Support;

use App\Channels\TelnyxChannel;
use App\Channels\TwilioChannel;

class SmsChannel
{
    /**
     * Get the configured SMS channel class.
     *
     * @return class-string
     */
    public static function get(): string
    {
        $provider = config('services.sms.provider', 'telnyx');

        return match ($provider) {
            'twilio' => TwilioChannel::class,
            'telnyx' => TelnyxChannel::class,
            default => TelnyxChannel::class,
        };
    }
}

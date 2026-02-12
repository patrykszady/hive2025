<?php

namespace App\Support;

use App\Channels\TelnyxChannel;

class SmsChannel
{
    /**
     * Get the configured SMS channel class.
     *
     * @return class-string
     */
    public static function get(): string
    {
        return TelnyxChannel::class;
    }
}

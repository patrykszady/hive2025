<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

class SmsScheduleService
{
    /**
     * Check if current time is within business hours.
     *
     * @param  string|null  $timezone  Override timezone (e.g., vendor's timezone). If null, uses config default.
     */
    public function isWithinBusinessHours(?string $timezone = null): bool
    {
        $tz = $timezone ?: config('sms.business_hours.timezone', 'America/Chicago');
        $now = Carbon::now($tz);

        return $this->isTimeWithinBusinessHours($now);
    }

    /**
     * Check if a specific time is within business hours.
     */
    public function isTimeWithinBusinessHours(Carbon $time): bool
    {
        $tz = config('sms.business_hours.timezone', 'America/Chicago');
        $now = $time->copy()->setTimezone($tz);

        // Check weekends
        if (config('sms.business_hours.skip_weekends', true)) {
            if ($now->isWeekend()) {
                return false;
            }
        }

        $startHour = config('sms.business_hours.start_hour', 7);
        $startMinute = config('sms.business_hours.start_minute', 0);
        $endHour = config('sms.business_hours.end_hour', 20);
        $endMinute = config('sms.business_hours.end_minute', 0);

        $start = $now->copy()->setTime($startHour, $startMinute, 0);
        $end = $now->copy()->setTime($endHour, $endMinute, 0);

        return $now->greaterThanOrEqualTo($start) && $now->lessThan($end);
    }

    /**
     * Get the next business hours start time.
     *
     * @param  string|null  $timezone  Override timezone. If null, uses config default.
     */
    public function getNextBusinessHoursStart(?string $timezone = null): Carbon
    {
        $tz = $timezone ?: config('sms.business_hours.timezone', 'America/Chicago');
        $now = Carbon::now($tz);

        $startHour = config('sms.business_hours.start_hour', 7);
        $startMinute = config('sms.business_hours.start_minute', 0);

        $startToday = $now->copy()->setTime($startHour, $startMinute, 0);

        // If before today's start, return today's start
        if ($now->lessThan($startToday)) {
            // But skip weekends if configured
            if (config('sms.business_hours.skip_weekends', true) && $startToday->isWeekend()) {
                return $this->getNextWeekdayStart($startToday);
            }

            return $startToday;
        }

        // Otherwise, return tomorrow's (or next weekday's) start
        $startTomorrow = $now->copy()->addDay()->setTime($startHour, $startMinute, 0);

        if (config('sms.business_hours.skip_weekends', true) && $startTomorrow->isWeekend()) {
            return $this->getNextWeekdayStart($startTomorrow);
        }

        return $startTomorrow;
    }

    /**
     * Get the next weekday's business hours start from a given date.
     */
    protected function getNextWeekdayStart(Carbon $from): Carbon
    {
        $startHour = config('sms.business_hours.start_hour', 7);
        $startMinute = config('sms.business_hours.start_minute', 0);

        $date = $from->copy();

        while ($date->isWeekend()) {
            $date->addDay();
        }

        return $date->setTime($startHour, $startMinute, 0);
    }

    /**
     * Get today's date in the business timezone.
     *
     * @param  string|null  $timezone  Override timezone. If null, uses config default.
     */
    public function getToday(?string $timezone = null): Carbon
    {
        $tz = $timezone ?: config('sms.business_hours.timezone', 'America/Chicago');

        return Carbon::today($tz);
    }

    /**
     * Get tomorrow's date in the business timezone.
     *
     * @param  string|null  $timezone  Override timezone. If null, uses config default.
     */
    public function getTomorrow(?string $timezone = null): Carbon
    {
        $tz = $timezone ?: config('sms.business_hours.timezone', 'America/Chicago');

        return Carbon::tomorrow($tz);
    }

    /**
     * Get the delay in minutes for day-of-change notifications.
     */
    public function getChangeDelayMinutes(): int
    {
        return config('sms.day_of_changes.delay_minutes', 5);
    }

    /**
     * Get the throttle window in minutes for day-of-change notifications.
     */
    public function getThrottleMinutes(): int
    {
        return config('sms.day_of_changes.throttle_minutes', 30);
    }

    /**
     * Get the logger for a specific SMS type.
     *
     * @param  string  $type  One of: 'client', 'vendor', 'team'
     */
    public function getLogger(string $type): LoggerInterface
    {
        $channel = config("sms.log_channels.{$type}", 'stack');

        return Log::channel($channel);
    }

    /**
     * Get the default business timezone.
     */
    public function getDefaultTimezone(): string
    {
        return config('sms.business_hours.timezone', 'America/Chicago');
    }
}

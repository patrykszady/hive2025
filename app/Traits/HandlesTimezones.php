<?php

namespace App\Traits;

use Carbon\Carbon;

trait HandlesTimezones
{
    /**
     * Convert date from external API timezone to UTC for database storage
     */
    protected function convertToUtc($date, string $fromTimezone = 'America/Chicago'): ?Carbon
    {
        if (!$date) {
            return null;
        }

        try {
            // If it's already a Carbon instance
            if ($date instanceof Carbon) {
                return $date->timezone('UTC');
            }

            // Parse the date and convert to UTC
            return Carbon::parse($date, $fromTimezone)->timezone('UTC');
        } catch (\Exception $e) {
            \Log::error('Timezone conversion error', [
                'date' => $date,
                'from_timezone' => $fromTimezone,
                'error' => $e->getMessage(),
            ]);
            
            return null;
        }
    }

    /**
     * Convert UTC date to vendor's timezone
     */
    protected function convertToVendorTimezone($date): ?Carbon
    {
        if (!$date) {
            return null;
        }

        $vendor = auth()->user()?->vendor;
        $timezone = $vendor?->timezone ?? config('app.timezone', 'America/Chicago');

        try {
            if ($date instanceof Carbon) {
                return $date->timezone($timezone);
            }

            return Carbon::parse($date, 'UTC')->timezone($timezone);
        } catch (\Exception $e) {
            \Log::error('Vendor timezone conversion error', [
                'date' => $date,
                'vendor_timezone' => $timezone,
                'error' => $e->getMessage(),
            ]);
            
            return null;
        }
    }

    /**
     * Parse date from API ensuring it's stored as UTC
     * 
     * Common API timezone mappings:
     * - Plaid: America/Chicago or user's timezone
     * - Nylas: UTC
     * - Microsoft: User's timezone (check MSGRAPH_PREFER_TIMEZONE)
     */
    protected function parseApiDate($date, string $apiSource = 'plaid'): ?Carbon
    {
        if (!$date) {
            return null;
        }

        $timezoneMap = [
            'plaid' => 'America/Chicago',
            'nylas' => 'UTC',
            'microsoft' => config('msgraph.prefer_timezone', 'America/Chicago'),
            'google' => 'UTC',
        ];

        $sourceTimezone = $timezoneMap[strtolower($apiSource)] ?? 'America/Chicago';

        return $this->convertToUtc($date, $sourceTimezone);
    }
}

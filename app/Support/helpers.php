<?php

use Regulus\TetraText\Facade as TetraText;

if (! function_exists('display_money')) {
    function display_money(float|int|string $value): string
    {
        $formatted = TetraText::money($value);

        return str_ends_with($formatted, '.00')
            ? substr($formatted, 0, -3)
            : $formatted;
    }
}

if (! function_exists('amount_in_words')) {
    /**
     * Spell a dollar amount out check-style: "two thousand three hundred
     * ninety-seven and 50/100" ($2,397.50), "seven and 04/100" ($7.04).
     */
    function amount_in_words(float|int|string $value): string
    {
        $value   = round((float) $value, 2);
        $dollars = (int) floor(abs($value));
        $cents   = (int) round((abs($value) - $dollars) * 100);

        $words = (new \NumberFormatter('en_US', \NumberFormatter::SPELLOUT))->format($dollars);

        return ($value < 0 ? 'minus ' : '') . $words . ' and ' . str_pad((string) $cents, 2, '0', STR_PAD_LEFT) . '/100';
    }
}

if (! function_exists('browser_timezone')) {
    function browser_timezone(): string
    {
        // First check session (set by Livewire component)
        $timezone = session('browser.timezone');

        if (is_string($timezone) && $timezone !== '') {
            return $timezone;
        }

        // Fallback to cookie (set by JavaScript immediately on page load)
        $cookieTimezone = request()->cookie('browser_timezone');
        if (is_string($cookieTimezone) && $cookieTimezone !== '') {
            return $cookieTimezone;
        }

        // Fallback to vendor timezone
        $vendorTimezone = auth()->user()?->vendor?->timezone;

        return is_string($vendorTimezone) && $vendorTimezone !== ''
            ? $vendorTimezone
            : (string) config('app.timezone');
    }
}

if (! function_exists('vendor_timezone')) {
    /**
     * Get the authenticated user's vendor timezone.
     * Use this for server-side rendering like PDFs where browser timezone doesn't apply.
     */
    function vendor_timezone(): string
    {
        $vendorTimezone = auth()->user()?->vendor?->timezone;

        return is_string($vendorTimezone) && $vendorTimezone !== ''
            ? $vendorTimezone
            : (string) config('app.timezone');
    }
}

if (! function_exists('browser_date')) {
    function browser_date(): ?string
    {
        // First check session (set by Livewire component)
        $date = session('browser.date');

        if (is_string($date) && $date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $date;
        }

        // Fallback to cookie (set by JavaScript immediately on page load)
        $cookieDate = request()->cookie('browser_date');
        if (is_string($cookieDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $cookieDate)) {
            return $cookieDate;
        }

        return null;
    }
}

if (! function_exists('browser_today')) {
    function browser_today(): \Illuminate\Support\Carbon
    {
        // Allow faking the browser date for testing (e.g., FAKE_BROWSER_DATE="-1 day" or "2025-12-26")
        $fakeDate = config('app.fake_browser_date') ?: env('FAKE_BROWSER_DATE');
        if ($fakeDate) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fakeDate)) {
                return \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $fakeDate, browser_timezone())->startOfDay();
            }
            return \Illuminate\Support\Carbon::parse($fakeDate, browser_timezone())->startOfDay();
        }

        $date = browser_date();

        if ($date) {
            return \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $date, browser_timezone())
                ->startOfDay();
        }

        // Fallback: use vendor timezone if available, otherwise app timezone
        // This provides a better default when browser hasn't synced yet
        $fallbackTimezone = vendor_timezone();

        return \Illuminate\Support\Carbon::today($fallbackTimezone);
    }
}

if (! function_exists('task_timezone')) {
    /**
     * Get the timezone for a task based on its hive vendor (belongs_to_vendor / owner).
     */
    function task_timezone(\App\Models\Task $task): string
    {
        $hiveVendor = $task->owner;

        if ($hiveVendor && is_string($hiveVendor->timezone) && $hiveVendor->timezone !== '') {
            return $hiveVendor->timezone;
        }

        return (string) config('app.timezone');
    }
}

if (! function_exists('task_time_in_browser_tz')) {
    /**
     * Convert a task's time_settings time from hive vendor timezone to browser timezone.
     *
     * @param  \App\Models\Task  $task
     * @param  string  $dateKey  The date key (Y-m-d format)
     * @param  string  $timeKey  The time key ('start_time' or 'end_time')
     * @return string|null  The time in browser timezone (e.g., "8:00 AM") or null
     */
    function task_time_in_browser_tz(\App\Models\Task $task, string $dateKey, string $timeKey = 'start_time'): ?string
    {
        $timeSettings = $task->options?->time_settings ?? null;

        if (! $timeSettings) {
            return null;
        }

        $daySettings = is_object($timeSettings) ? ($timeSettings->$dateKey ?? null) : ($timeSettings[$dateKey] ?? null);

        if (! $daySettings) {
            return null;
        }

        $useTime = is_object($daySettings) ? ($daySettings->use_time ?? false) : ($daySettings['use_time'] ?? false);

        if (! $useTime) {
            return null;
        }

        $time = is_object($daySettings) ? ($daySettings->$timeKey ?? null) : ($daySettings[$timeKey] ?? null);

        if (! $time) {
            return null;
        }

        $taskTz = task_timezone($task);
        $browserTz = browser_timezone();

        // Create datetime in task's timezone, then convert to browser timezone
        $datetime = \Illuminate\Support\Carbon::createFromFormat(
            'Y-m-d H:i',
            "{$dateKey} {$time}",
            $taskTz
        )->setTimezone($browserTz);

        return $datetime->format('g:i A');
    }
}

if (! function_exists('task_datetime_in_browser_tz')) {
    /**
     * Get a task's date + time as a Carbon instance in browser timezone.
     *
     * @param  \App\Models\Task  $task
     * @param  string  $dateKey  The date key (Y-m-d format)
     * @param  string  $timeKey  The time key ('start_time' or 'end_time')
     * @return \Illuminate\Support\Carbon  The datetime in browser timezone
     */
    function task_datetime_in_browser_tz(\App\Models\Task $task, string $dateKey, string $timeKey = 'start_time'): \Illuminate\Support\Carbon
    {
        $timeSettings = $task->options?->time_settings ?? null;
        $taskTz = task_timezone($task);
        $browserTz = browser_timezone();

        $time = null;

        if ($timeSettings) {
            $daySettings = is_object($timeSettings) ? ($timeSettings->$dateKey ?? null) : ($timeSettings[$dateKey] ?? null);

            if ($daySettings) {
                $useTime = is_object($daySettings) ? ($daySettings->use_time ?? false) : ($daySettings['use_time'] ?? false);

                if ($useTime) {
                    $time = is_object($daySettings) ? ($daySettings->$timeKey ?? null) : ($daySettings[$timeKey] ?? null);
                }
            }
        }

        if ($time) {
            return \Illuminate\Support\Carbon::createFromFormat(
                'Y-m-d H:i',
                "{$dateKey} {$time}",
                $taskTz
            )->setTimezone($browserTz);
        }

        // No time set, return start of day in browser timezone
        return \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $dateKey, $browserTz)->startOfDay();
    }
}

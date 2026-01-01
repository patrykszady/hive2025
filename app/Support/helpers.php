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

if (! function_exists('browser_timezone')) {
    function browser_timezone(): string
    {
        $timezone = session('browser.timezone');

        if (is_string($timezone) && $timezone !== '') {
            return $timezone;
        }

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
        $date = session('browser.date');

        if (! is_string($date) || $date === '') {
            return null;
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null;
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

        return \Illuminate\Support\Carbon::today(browser_timezone());
    }
}

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
        $date = browser_date();

        if ($date) {
            return \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $date, browser_timezone())
                ->startOfDay();
        }

        return \Illuminate\Support\Carbon::today(browser_timezone());
    }
}

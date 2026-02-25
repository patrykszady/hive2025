<?php

namespace App\Traits;

trait DetectsDeviceType
{
    /**
     * Resolve the device type from a User-Agent string.
     *
     * Returns: 'iOS', 'Android', 'Windows', 'macOS', 'Linux', or 'Unknown'.
     */
    protected function resolveDeviceTypeFromUserAgent(string $userAgent): string
    {
        $ua = strtolower($userAgent);

        if ($ua === '') {
            return 'Unknown';
        }

        if (str_contains($ua, 'iphone') || str_contains($ua, 'ipad') || str_contains($ua, 'ios')) {
            return 'iOS';
        }

        if (str_contains($ua, 'android')) {
            return 'Android';
        }

        if (str_contains($ua, 'windows')) {
            return 'Windows';
        }

        if (str_contains($ua, 'macintosh') || str_contains($ua, 'mac os x')) {
            return 'macOS';
        }

        if (str_contains($ua, 'linux')) {
            return 'Linux';
        }

        return 'Unknown';
    }

    /**
     * Get the device type for the current HTTP request.
     */
    protected function currentDeviceType(): string
    {
        return $this->resolveDeviceTypeFromUserAgent((string) request()->header('User-Agent'));
    }
}

<?php

namespace App\Services;

use App\Models\ShortLink;

class UrlShortener
{
    /**
     * Shorten an absolute URL into an internal /l/{code} link on our own
     * domain that 302-redirects straight to the destination (no interstitial).
     *
     * A given destination always maps to the same short link. Falls back to
     * the original URL when shortening is disabled or the URL is not absolute.
     */
    public function shorten(string $url): string
    {
        if (! config('services.url_shortener.enabled', true)) {
            return $url;
        }

        if (! preg_match('#^https?://#i', $url)) {
            return $url;
        }

        $link = ShortLink::forDestination($url);

        $baseUrl = config('app.dev_webhook_url') ?: rtrim((string) config('app.url'), '/');

        return "{$baseUrl}/l/{$link->code}";
    }
}

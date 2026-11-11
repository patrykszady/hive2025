<?php

namespace App\Support\Ui;

use Illuminate\Http\Request;
use Livewire\Livewire;

/**
 * Whether the page being rendered uses Flux 2.21's card parts for its cards
 * and modals (config/ui.php). Decided once per request from the page's own
 * path — a Livewire update request has /livewire/… as its path, so there the
 * page's address is the Referer, where Livewire's #[Url] reads it too (the
 * same rule as App\Livewire\Concerns\RemembersFilters::pageQueryString).
 *
 * The decision is remembered for the request it was made for: the next
 * request (a test's next GET, a Livewire round trip) decides again.
 */
class CardsV2
{
    protected static ?Request $decidedFor = null;

    protected static bool $active = false;

    public static function active(): bool
    {
        $request = app()->bound('request') ? app('request') : null;
        if (! $request instanceof Request) {
            return self::decide(null);
        }

        if (self::$decidedFor !== $request) {
            self::$decidedFor = $request;
            self::$active = self::decide($request);
        }

        return self::$active;
    }

    /** Forget the decision (tests that change config or the request mid-way). */
    public static function reset(): void
    {
        self::$decidedFor = null;
        self::$active = false;
    }

    protected static function decide(?Request $request): bool
    {
        if (! config('ui.cards_v2')) {
            return false;
        }

        $paths = (array) config('ui.cards_v2_paths', []);
        if ($paths === []) {
            return true;
        }

        $path = '/'.ltrim(self::pagePath($request), '/');
        foreach ($paths as $prefix) {
            $prefix = '/'.trim((string) $prefix, '/');
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    protected static function pagePath(?Request $request): string
    {
        if ($request === null) {
            return '';
        }
        if (Livewire::isLivewireRequest()) {
            return (string) parse_url((string) $request->header('Referer'), PHP_URL_PATH);
        }

        return $request->path();
    }
}

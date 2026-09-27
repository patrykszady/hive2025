<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Controller;
use SsSystems\Platform\Http\Admin\Concerns\BuildsAnalyticsScreen;
use SsSystems\Platform\Http\Admin\Concerns\BuildsApiResponses;
use SsSystems\Platform\Http\Admin\Contracts\AnalyticsEventReader;
use SsSystems\Platform\Http\Admin\PulseAnalyticsEventReader;
use SsSystems\Platform\Pulse\Contracts\PulseStorage;

/**
 * Management API for ss-systems' Livewire\Admin\SiteAnalytics screen —
 * events()/summary() now come from the kit's BuildsAnalyticsScreen trait
 * (0.13.0) — this class only supplies the two hooks it needs: the row
 * source (PulseAnalyticsEventReader, over the kit's own Pulse `site_events`
 * table instead of a TrackedEvent table) and this site's own effective
 * timezone. Every other behaviour (the `days`/`type_filter` scope, the
 * four-tile breakdown, the trend chart, top pages, pagination, response
 * shape, the 5-minute summary cache) is unchanged.
 *
 *   phone_click -> the `call` event (delegated tel: click tracking, see
 *                  SsSystems\Platform\Pulse\BeaconScript).
 *   email_click -> the `email` event (delegated mailto: click tracking).
 *   cta_click   -> the `signup` event: a click on a marketing page's
 *                  "Get started"/sign-up call to action, fired by the
 *                  delegated listener in components/layouts/guest.blade.php
 *                  (AppServiceProvider's `feature_labels`: 'Sign-up clicks').
 *                  It carries no name/email/phone — that is the Leads
 *                  screen's job (App\Http\Controllers\Api\Admin\V1\
 *                  LeadController, reading actual registrations), never
 *                  this one's. Its label is a static string (the signup
 *                  beacon carries no meta at all) rather than the default
 *                  meta['n'] every other event type resolves to.
 *   form_submit -> always 0. hive.contractors' marketing site has no
 *                  contact form at all, so there is no row this type could
 *                  ever count — the key stays present (the shared screen's
 *                  four tiles/chart lines/legend all key off it) but is
 *                  never populated, the same honest-zero convention
 *                  SeoSnapshotController's docblock describes for a section
 *                  this app genuinely has none of.
 */
class AnalyticsController extends Controller
{
    use BuildsApiResponses;
    use BuildsAnalyticsScreen;

    /** site_events.event -> the type it maps to; 'signup' is this site's only cta_click source. */
    protected const EVENT_TYPES = [
        'call' => 'phone_click',
        'email' => 'email_click',
        'signup' => 'cta_click',
    ];

    protected function analyticsEventReader(): AnalyticsEventReader
    {
        return new PulseAnalyticsEventReader(
            app(PulseStorage::class),
            self::EVENT_TYPES,
            fn (string $event, array $meta) => match ($event) {
                'call', 'email' => is_string($meta['n'] ?? null) ? $meta['n'] : null,
                // The signup beacon carries no meta at all (see BeaconScript's
                // delegated listener in guest.blade.php) — a static label, same
                // as dawnsellshomes' 'saved' cta row.
                'signup' => 'Sign-up link clicked',
                default => null,
            },
        );
    }

    /** All analytics times are presented in Central Time (Chicago) — matches Pulse's own SnapshotBuilder timezone. */
    protected function analyticsTimezone(): string
    {
        return config('services.analytics.timezone', 'America/Chicago');
    }
}

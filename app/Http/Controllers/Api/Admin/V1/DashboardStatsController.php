<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Vendor;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use SsSystems\Platform\Dashboard\Delta;

/**
 * GET /api/admin/v1/dashboard-stats — the central admin's dashboard tiles,
 * each {key, label, value, note, delta_pct, href}. The first four are the
 * row every connected site shares (leads, contacts, search_clicks,
 * reviews — the admin's own rule), then this site's own two. For this app
 * a "lead" is a company sign-up (the same rows the Leads screen lists —
 * see LeadController), a contact is a call or email click recorded by
 * Site Pulse, search clicks come from the Search Console / Bing daily
 * totals once the SEO sync fills them, and reviews are the published
 * testimonials. Cached five minutes: the admin reads this on every
 * dashboard load, and nothing here changes faster.
 */
class DashboardStatsController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                // The summary the platform dashboard's card reads (ss-systems'
                // SiteOverview: leads today / this week / pending / total),
                // from the same sign-ups the Leads screen lists — without it
                // the card showed 0 leads beside 43 sign-ups.
                'leads' => $this->leadsSummary(),
                'tiles' => Cache::remember('admin.dashboard.tiles', now()->addMinutes(5), fn () => [
                    $this->leadsTile(),
                    $this->contactsTile(),
                    $this->searchClicksTile(),
                    $this->reviewsTile(),
                    $this->signupsTile(),
                    $this->usersTile(),
                ]),
            ],
        ]);
    }

    /** @return array{total: int, today: int, this_week: int, pending: int} */
    protected function leadsSummary(): array
    {
        $signups = fn () => User::query()->whereNotNull('registration');

        return [
            'total' => $signups()->count(),
            'today' => $signups()->whereDate('created_at', now()->toDateString())->count(),
            'this_week' => $signups()->where('created_at', '>=', now()->subWeek())->count(),
            // Sign-ups are accounts: there is nothing to review or mark.
            'pending' => 0,
        ];
    }

    /** Sign-ups: the users who came through the public registration flow (LeadController's exact marker). */
    protected function leadsTile(): array
    {
        $now = now();
        $query = fn () => User::query()->whereNotNull('registration');

        $current = $query()->where('created_at', '>=', (clone $now)->subDays(7))->count();
        $prior = $query()
            ->where('created_at', '>=', (clone $now)->subDays(14))
            ->where('created_at', '<', (clone $now)->subDays(7))
            ->count();

        return [
            'key' => 'leads',
            'label' => 'Sign-ups (7 days)',
            'value' => $current,
            'note' => number_format($query()->count()).' total',
            'delta_pct' => Delta::pct($current, $prior),
            'href' => 'leads',
        ];
    }

    /** Calls and emails: Site Pulse's call/email click events (this site has no form). */
    protected function contactsTile(): array
    {
        $now = now();
        $events = fn () => DB::table('site_events')->whereIn('event', ['call', 'email']);

        $current = $events()->where('created_at', '>=', (clone $now)->subDays(7))->count();
        $prior = $events()
            ->where('created_at', '>=', (clone $now)->subDays(14))
            ->where('created_at', '<', (clone $now)->subDays(7))
            ->count();

        return [
            'key' => 'contacts',
            'label' => 'Calls & emails (7 days)',
            'value' => $current,
            'note' => null,
            'delta_pct' => Delta::pct($current, $prior),
            'href' => 'analytics',
        ];
    }

    /**
     * Google + Bing clicks over the newest 7 days actually present in the
     * daily-totals tables (Search Console lags 2-3 days, so "the last 7
     * days" would undercount the newest ones), and the 7 days before those
     * for the delta — the same window gs.construction uses. Until the SEO
     * sync has created and filled those tables this reads as zero, never
     * as an error.
     */
    protected function searchClicksTile(): array
    {
        $tables = array_values(array_filter(['gsc_daily_totals', 'bing_daily_totals'], fn ($t) => Schema::hasTable($t)));

        $maxDate = collect($tables)
            ->map(fn ($t) => DB::table($t)->max('date'))
            ->filter()
            ->map(fn ($d) => Carbon::parse($d))
            ->max();

        if (! $maxDate) {
            return [
                'key' => 'search_clicks',
                'label' => 'Search clicks (7 days)',
                'value' => 0,
                'note' => '0 impressions',
                'delta_pct' => null,
                'href' => 'seo',
            ];
        }

        $end = $maxDate->copy()->startOfDay();
        $start = $end->copy()->subDays(6);
        $priorEnd = $start->copy()->subDay();
        $priorStart = $priorEnd->copy()->subDays(6);

        [$clicks, $impressions] = $this->searchTotals($tables, $start, $end);
        [$priorClicks] = $this->searchTotals($tables, $priorStart, $priorEnd);

        return [
            'key' => 'search_clicks',
            'label' => 'Search clicks (7 days)',
            'value' => $clicks,
            'note' => number_format($impressions).' impressions',
            'delta_pct' => Delta::pct($clicks, $priorClicks),
            'href' => 'seo',
        ];
    }

    /**
     * [clicks, impressions] summed across the given daily-totals tables.
     * whereDate() on both bounds, so the comparison is calendar-date-only
     * on every driver (MySQL stores a plain date; SQLite in tests keeps
     * whatever string was written).
     *
     * @param  array<int, string>  $tables
     * @return array{0:int,1:int}
     */
    protected function searchTotals(array $tables, Carbon $start, Carbon $end): array
    {
        $clicks = 0;
        $impressions = 0;

        foreach ($tables as $table) {
            $scoped = DB::table($table)
                ->whereDate('date', '>=', $start->toDateString())
                ->whereDate('date', '<=', $end->toDateString());

            $clicks += (int) (clone $scoped)->sum('clicks');
            $impressions += (int) (clone $scoped)->sum('impressions');
        }

        return [$clicks, $impressions];
    }

    /** Published testimonials, with how many arrived in the last 30 days against the 30 before. */
    protected function reviewsTile(): array
    {
        $now = now();
        $published = fn () => DB::table('testimonials')->where('is_published', true);

        $total = $published()->count();
        $new = $published()->where('created_at', '>=', (clone $now)->subDays(30))->count();
        $priorNew = $published()
            ->where('created_at', '>=', (clone $now)->subDays(60))
            ->where('created_at', '<', (clone $now)->subDays(30))
            ->count();

        return [
            'key' => 'reviews',
            'label' => 'Reviews',
            'value' => $total,
            'note' => '+'.number_format($new).' in 30 days',
            'delta_pct' => Delta::pct($new, $priorNew),
            'href' => 'reviews',
        ];
    }

    protected function signupsTile(): array
    {
        $now = now();

        $current = Vendor::query()->where('created_at', '>=', (clone $now)->subDays(7))->count();
        $prior = Vendor::query()
            ->where('created_at', '>=', (clone $now)->subDays(14))
            ->where('created_at', '<', (clone $now)->subDays(7))
            ->count();
        $total = Vendor::query()->count();

        return [
            'key' => 'signups',
            'label' => 'New companies (7 days)',
            'value' => $current,
            'note' => number_format($total).' total',
            'delta_pct' => Delta::pct($current, $prior),
            'href' => null,
        ];
    }

    protected function usersTile(): array
    {
        $now = now();

        $current = User::query()->where('created_at', '>=', (clone $now)->subDays(7))->count();
        $prior = User::query()
            ->where('created_at', '>=', (clone $now)->subDays(14))
            ->where('created_at', '<', (clone $now)->subDays(7))
            ->count();
        $total = User::query()->count();

        return [
            'key' => 'users',
            'label' => 'New users (7 days)',
            'value' => $current,
            'note' => number_format($total).' total',
            'delta_pct' => Delta::pct($current, $prior),
            'href' => null,
        ];
    }
}

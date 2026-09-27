<?php

namespace App\Http\Controllers\Api\Admin\V1;

use SsSystems\Platform\Http\Admin\Concerns\BuildsApiResponses;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Management API for ss-systems' Livewire\Admin\SiteAnalytics screen — same
 * two-call shape as gsc/jpeterson-design's/dawnsellshomes' own
 * Api\Admin\V1\AnalyticsController, read over App\Providers\
 * AppServiceProvider's Pulse `site_events` table instead of a TrackedEvent
 * table:
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
 *                  this one's.
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

    /** All analytics times are presented in Central Time (Chicago) — matches Pulse's own SnapshotBuilder timezone. */
    protected const TZ = 'America/Chicago';

    /**
     * The windows the screen's range picker offers — same set gsc/
     * jpeterson-design/dawnsellshomes use.
     *
     * @var array<int,int>
     */
    public const SPANS = [7, 14, 28, 60, 90, 180, 360];

    public const DEFAULT_SPAN = 28;

    public const TYPE_PHONE_CLICK = 'phone_click';

    public const TYPE_EMAIL_CLICK = 'email_click';

    public const TYPE_FORM_SUBMIT = 'form_submit';

    public const TYPE_CTA_CLICK = 'cta_click';

    /** site_events.event -> the type it maps to; 'signup' is this site's only cta_click source. */
    protected const EVENT_TYPES = [
        'call' => self::TYPE_PHONE_CLICK,
        'email' => self::TYPE_EMAIL_CLICK,
        'signup' => self::TYPE_CTA_CLICK,
    ];

    public function events(Request $request): JsonResponse
    {
        $days = $this->days($request);
        $start = $this->windowStart($days);
        $type = $this->typeFilter($request);

        $rows = $this->rowsSince($start)
            ->when($type !== null, fn (Collection $rows) => $rows->where('type', $type))
            ->values();

        $perPage = $this->perPage($request);
        $page = max(1, (int) $request->integer('page', 1));

        $paginator = new LengthAwarePaginator(
            items: $rows->forPage($page, $perPage)->values(),
            total: $rows->count(),
            perPage: $perPage,
            currentPage: $page,
            options: ['path' => $request->url(), 'query' => $request->query()],
        );

        return $this->paginatedResponse($paginator, fn (array $row) => $this->toApiArray($row));
    }

    public function summary(Request $request): JsonResponse
    {
        $days = $this->days($request);
        $type = $this->typeFilter($request);

        // Cached a few minutes, keyed by the scope and a 5-minute clock
        // bucket — matches dawnsellshomes' own summary() caching, since
        // this read always scans two windows' worth of site_events.
        $bucket = intdiv(now()->getTimestamp(), 300);
        $cacheKey = "analytics-summary:{$days}:{$type}:{$bucket}";

        $data = Cache::remember($cacheKey, 300, function () use ($days, $type) {
            $start = $this->windowStart($days);
            $priorStart = $start->copy()->subDays($days);

            $rows = $this->rowsSince($priorStart);
            $current = $rows->filter(fn (array $row) => $row['created_at']->gte($start))->values();
            $prior = $rows->filter(fn (array $row) => $row['created_at']->lt($start))->values();

            // The tiles are the per-type breakdown of the window, so they
            // never narrow by type — the selected type is highlighted on
            // the screen instead of the other three collapsing to zero.
            $stats = $this->countsByType($current);
            $statsPrev = $this->countsByType($prior);

            $topPages = $current
                ->when($type !== null, fn (Collection $rows) => $rows->where('type', $type))
                ->filter(fn (array $row) => $row['page_path'] !== null)
                ->groupBy('page_path')
                ->map(fn (Collection $rows) => $rows->count())
                ->sortDesc()
                ->take(8);

            return [
                'days' => $days,
                'stats' => $stats,
                'stats_prev' => $statsPrev,
                'top_pages' => $topPages,
                'trend' => $this->trendChartData($days, $current),
            ];
        });

        return response()->json(['data' => $data]);
    }

    protected function days(Request $request): int
    {
        $days = (int) $request->integer('days', self::DEFAULT_SPAN);

        return in_array($days, self::SPANS, true) ? $days : self::DEFAULT_SPAN;
    }

    /**
     * "Last N days" is today plus the N-1 days before it, on the Chicago
     * calendar, converted to UTC so it compares correctly against the
     * UTC-stored created_at values.
     */
    protected function windowStart(int $days): Carbon
    {
        return Carbon::now(self::TZ)->subDays($days - 1)->startOfDay()->utc();
    }

    protected function typeFilter(Request $request): ?string
    {
        $type = $request->string('type_filter')->toString();

        return ($type !== '' && $type !== 'all') ? $type : null;
    }

    /** @return array<string,int> */
    protected function countsByType(Collection $rows): array
    {
        $counts = [
            'phone' => $rows->where('type', self::TYPE_PHONE_CLICK)->count(),
            'email' => $rows->where('type', self::TYPE_EMAIL_CLICK)->count(),
            // Always 0 — see the class docblock. No row this collection can
            // ever hold carries this type.
            'form' => $rows->where('type', self::TYPE_FORM_SUBMIT)->count(),
            'cta' => $rows->where('type', self::TYPE_CTA_CLICK)->count(),
        ];
        $counts['total'] = array_sum($counts);

        return $counts;
    }

    /**
     * Daily per-type event counts for the window, every type in every row:
     * the screen draws only the selected type's line, so one series serves
     * every type filter. Grouped by the Chicago calendar day in PHP so DST
     * transitions stay correct and the same code runs against SQLite in
     * tests.
     *
     * @param  Collection<int,array<string,mixed>>  $rows  rows already scoped to the window
     * @return array<int,array<string,mixed>>
     */
    protected function trendChartData(int $days, Collection $rows): array
    {
        $byDay = $rows->groupBy(fn (array $row) => $row['created_at']->copy()->timezone(self::TZ)->toDateString());

        return collect(range($days - 1, 0))->map(function ($ago) use ($byDay) {
            $date = Carbon::now(self::TZ)->subDays($ago)->toDateString();
            $dayRows = $byDay->get($date, collect());
            $types = $dayRows->countBy('type');

            return [
                'date' => Carbon::parse($date)->format('M j'),
                // ISO day for the chart's time axis; 'date' stays the tooltip label.
                'day' => $date,
                'phone' => (int) ($types[self::TYPE_PHONE_CLICK] ?? 0),
                'email' => (int) ($types[self::TYPE_EMAIL_CLICK] ?? 0),
                'form' => (int) ($types[self::TYPE_FORM_SUBMIT] ?? 0),
                'cta' => (int) ($types[self::TYPE_CTA_CLICK] ?? 0),
                'total' => $dayRows->count(),
            ];
        })->values()->all();
    }

    /**
     * Every normalized row since $start, newest first — the site_events
     * rows this screen understands (call/email/signup). Not cached itself;
     * summary() caches its own computed result instead, and events() wants
     * the table always live.
     *
     * @return Collection<int,array<string,mixed>>
     */
    protected function rowsSince(Carbon $start): Collection
    {
        return DB::table('site_events')
            ->whereIn('event', array_keys(self::EVENT_TYPES))
            ->where('created_at', '>=', $start)
            ->orderByDesc('created_at')
            ->get(['id', 'event', 'path', 'meta', 'created_at'])
            ->map(fn ($row) => $this->siteEventRow($row))
            ->values();
    }

    /** @return array<string,mixed> */
    protected function siteEventRow(object $row): array
    {
        $meta = json_decode((string) $row->meta, true) ?: [];

        $type = self::EVENT_TYPES[$row->event] ?? self::TYPE_CTA_CLICK;

        $label = match ($row->event) {
            'call', 'email' => is_string($meta['n'] ?? null) ? $meta['n'] : null,
            // The signup beacon carries no meta at all (see BeaconScript's
            // delegated listener in guest.blade.php) — a static label, same
            // as dawnsellshomes' 'saved' cta row.
            'signup' => 'Sign-up link clicked',
            default => null,
        };

        return [
            'id' => "se-{$row->id}",
            'type' => $type,
            'label' => $label,
            'page_path' => $this->normalizePath($row->path),
            // Never captured by this site's beacon.
            'referrer' => null,
            'utm_source' => null,
            'utm_medium' => null,
            'utm_campaign' => null,
            'country' => null,
            'created_at' => Carbon::parse($row->created_at),
        ];
    }

    protected function normalizePath(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        return '/'.ltrim($path, '/');
    }

    /** @param  array<string,mixed>  $row */
    protected function toApiArray(array $row): array
    {
        return [
            'id' => $row['id'],
            'type' => $row['type'],
            'type_label' => self::typeLabel($row['type']),
            'label' => $row['label'],
            'page_path' => $row['page_path'],
            'referrer' => $row['referrer'],
            'utm_source' => $row['utm_source'],
            'utm_medium' => $row['utm_medium'],
            'utm_campaign' => $row['utm_campaign'],
            'country' => $row['country'],
            'created_at' => $row['created_at']->toIso8601String(),
        ];
    }

    public static function typeLabel(string $type): string
    {
        return match ($type) {
            self::TYPE_PHONE_CLICK => 'Phone click',
            self::TYPE_EMAIL_CLICK => 'Email click',
            self::TYPE_FORM_SUBMIT => 'Form submission',
            self::TYPE_CTA_CLICK => 'CTA click',
            default => ucfirst(str_replace('_', ' ', $type)),
        };
    }
}

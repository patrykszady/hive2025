<?php

namespace App\Http\Controllers\Api\Admin\V1;

use SsSystems\Platform\Http\Admin\Concerns\BuildsApiResponses;
use App\Http\Controllers\Controller;
use App\Support\Seo\Reports\ReportCapabilities;
use App\Support\SeoReportRun;
use App\Support\SeoStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\CommonMark\GithubFlavoredMarkdownConverter;

/**
 * GET seo/reports, GET seo/reports/{report}, POST seo/reports/{report}/
 * regenerate — the shared SEO report library's admin surface. Ported from
 * dawnsellshomes' identical controller: this app's live "snapshot" data
 * (search performance, health, gsc-errors, sitemaps) has its own
 * controller (SeoSnapshotController), which this class shares its
 * `SEARCH_SNAPSHOT_CACHE_PREFIX` cache-busting convention with — see its
 * regenerate()/SeoSnapshotController::refreshSnapshot().
 *
 * See App\Support\Seo\Reports\ReportCapabilities for which of the ten kit
 * reports this site can actually run.
 */
class SeoReportController extends Controller
{
    use BuildsApiResponses;

    protected function reports(): array
    {
        return (array) config('seo-reports.reports', []);
    }

    public function index(): JsonResponse
    {
        $files = $this->files();

        return $this->itemResponse([
            'reports' => $files,
            'stats' => $this->reportStats($files),
        ]);
    }

    public function show(Request $request, string $report): JsonResponse
    {
        $reports = $this->reports();
        if (! isset($reports[$report])) {
            abort(404, "Unknown report \"{$report}\".");
        }

        return $this->itemResponse($this->reportPayload($report, $reports[$report]));
    }

    /**
     * A report whose `requires` this site cannot meet is refused BEFORE
     * running anything: HTTP 200, ok:false, status:'unavailable', the
     * owner-facing reason as `message`, run:null. See ss-systems'
     * SeoReportsAvailabilityTest for the exact contract this pins.
     */
    public function regenerate(Request $request, string $report): JsonResponse
    {
        $reports = $this->reports();
        if (! isset($reports[$report])) {
            abort(404, "Unknown report \"{$report}\".");
        }

        $availability = ReportCapabilities::availability($report);

        if (! $availability['available']) {
            Log::channel('seo-reports')->info('report run refused', [
                'key' => $report,
                'missing' => $availability['missing'],
                'requested_by' => $request->header('X-Admin-User'),
                'screen' => $request->header('X-Admin-Screen'),
            ]);

            $payload = $this->reportPayload($report, $reports[$report]);
            $payload['ok'] = false;
            $payload['status'] = 'unavailable';
            $payload['message'] = $availability['reason'];
            $payload['run'] = null;

            return $this->itemResponse($payload);
        }

        $trendDays = (int) $request->integer('trend_days', 14);

        $run = SeoReportRun::run($report, $reports[$report], $request, $trendDays);

        Cache::forget('admin.seo-reports.health-snapshot');
        Cache::forget(SeoSnapshotController::SEARCH_SNAPSHOT_CACHE_PREFIX.$trendDays);

        $payload = $this->reportPayload($report, $reports[$report]);
        $payload['ok'] = $run['ok'];
        $payload['status'] = $run['status'];
        $payload['message'] = $run['message'];
        $payload['run'] = $run;

        return $this->itemResponse($payload);
    }

    protected function reportPayload(string $key, array $meta): array
    {
        $file = $this->fileEntry($key, $meta);
        $file['html'] = $this->reportHtml($key);

        return $file;
    }

    /**
     * Public (not just an internal helper): App\Http\Controllers\Api\
     * Admin\V1\SeoSnapshotController's `report_stats` block reuses this and
     * reportStats() below rather than duplicating the availability/
     * freshness bookkeeping — see that controller's docblock.
     *
     * @return array<int, array<string, mixed>>
     */
    public function files(): array
    {
        return collect($this->reports())
            ->map(fn (array $meta, string $key) => $this->fileEntry($key, $meta))
            ->values()
            ->all();
    }

    protected function fileEntry(string $key, array $meta): array
    {
        $availability = ReportCapabilities::availability($key);

        $disk = Storage::disk('local');
        $path = SeoStorage::path("reports/{$key}.md");
        $exists = $disk->exists($path);
        $size = $exists ? $disk->size($path) : null;
        $mtimeTs = $exists ? $disk->lastModified($path) : null;
        $mtime = $mtimeTs ? Carbon::createFromTimestamp($mtimeTs) : null;
        $ageHours = $mtime ? (int) abs(now()->diffInHours($mtime)) : null;
        $freshnessPct = $ageHours === null ? 0 : max(0, 100 - (int) round(min($ageHours, 72) / 72 * 100));
        $status = ! $availability['available']
            ? 'unavailable'
            : ($ageHours === null ? 'missing' : ($ageHours <= 24 ? 'fresh' : 'stale'));

        return [
            'key' => $key,
            'label' => $meta['label'],
            'description' => $meta['description'],
            'command' => $meta['command'],
            'available' => $availability['available'],
            'missing' => $availability['missing'],
            'unavailable_reason' => $availability['reason'],
            'exists' => $exists,
            'size' => $size,
            'mtime' => $mtime?->toIso8601String(),
            'age' => $mtime?->diffForHumans(),
            'age_hours' => $ageHours,
            'freshness_pct' => $freshnessPct,
            'status' => $status,
        ];
    }

    public function reportStats(array $files): array
    {
        $files = collect($files);
        $available = $files->where('available', true);
        $generated = $available->where('exists', true);

        return [
            'total' => $available->count(),
            'generated' => $generated->count(),
            'fresh' => $available->where('status', 'fresh')->count(),
            'stale' => $available->where('status', 'stale')->count(),
            'missing' => $available->where('status', 'missing')->count(),
            'unavailable' => $files->where('available', false)->count(),
            'updated_today' => $generated->filter(fn (array $f) => ($f['age_hours'] ?? 9999) < 24)->count(),
            'last_update' => $generated
                ->sortByDesc(fn (array $f) => $f['mtime'] ? Carbon::parse($f['mtime'])->timestamp : 0)
                ->first()['age'] ?? null,
        ];
    }

    protected function reportHtml(string $key): string
    {
        $path = SeoStorage::path("reports/{$key}.md");
        $disk = Storage::disk('local');
        if (! $disk->exists($path)) {
            return '<p class="text-zinc-500">Report not yet generated. Click <strong>Run now</strong> to create it.</p>';
        }
        $md = (string) $disk->get($path);
        $converter = new GithubFlavoredMarkdownConverter([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);

        return (string) $converter->convert($md);
    }
}

<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Api\Admin\V1\Concerns\BuildsApiResponses;
use App\Http\Controllers\Controller;
use App\Models\PageSeoOverride;
use App\Support\MarketingPages;
use App\Support\PageSeoOverrides;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * ss-systems' Pages screen (App\Livewire\Admin\PageList there), over the
 * marketing site's Blade views instead of database rows — see
 * App\Support\MarketingPages for how those views are enumerated (the exact
 * same source App\Support\MarketingSitemap draws sitemap.xml from, plus the
 * two un-localized legal pages) and App\Models\PageSeoOverride for the one
 * table an edit here actually writes.
 *
 * Every page always exists, is always "published", and is always in the
 * sitemap — this site has no draft state and no way to exclude a route from
 * MarketingSitemap short of removing it from routes/web.php — so
 * `in_sitemap` always reads true and a PUT's `in_sitemap`/`canonical`/
 * `meta_keywords` (ss-systems' PageList sends all three; Dawn's reference
 * ServiceController/PageController ignore them too when a site has nothing
 * to back them with) are accepted and silently ignored rather than 422ing
 * a request the screen always sends this way.
 *
 * No page can be created or removed here: every one of them is code, not
 * data. POST/DELETE both refuse 422 with a message and no `errors` key, so
 * SiteApiException::isRefusal() is true and PageList shows it as a calm
 * notice rather than a red failure (see that class's store()/delete()).
 */
class PageController extends Controller
{
    use BuildsApiResponses;

    public function index(Request $request): JsonResponse
    {
        $rows = $this->rows();

        if ($search = trim((string) $request->string('search'))) {
            $needle = Str::lower($search);
            $rows = $rows->filter(fn (array $r) => Str::contains(Str::lower($r['title'] ?? ''), $needle)
                || Str::contains(Str::lower($r['path'] ?? ''), $needle));
        }

        if ($type = $request->string('type')->toString()) {
            $rows = $rows->where('type', $type);
        }

        // Every page is always in the sitemap — 'sitemap=0' (not in sitemap)
        // truthfully has nothing to show; 'all'/'1'/omitted show everything.
        if ($request->string('sitemap')->toString() === '0') {
            $rows = $rows->filter(fn () => false);
        }

        $sortColumn = match ($request->string('sort')->toString()) {
            'title' => 'title',
            'path' => 'path',
            default => 'updated_at',
        };
        $direction = strtolower($request->string('dir')->toString()) === 'asc' ? 'asc' : 'desc';

        $sorted = ($direction === 'asc' ? $rows->sortBy($sortColumn) : $rows->sortByDesc($sortColumn))->values();

        $perPage = $this->perPage($request, 20, 100);
        $page = max(1, $request->integer('page', 1));

        $paginator = new LengthAwarePaginator(
            $sorted->slice(($page - 1) * $perPage, $perPage)->values(),
            $sorted->count(),
            $perPage,
            $page
        );

        return $this->paginatedResponse($paginator, fn (array $row) => $row);
    }

    public function types(): JsonResponse
    {
        $rows = $this->rows()
            ->groupBy('type')
            ->map(fn (Collection $group, string $type) => ['type' => $type, 'count' => $group->count()])
            ->sortBy('type')
            ->values()
            ->all();

        return $this->itemResponse($rows);
    }

    public function show(int $page): JsonResponse
    {
        $override = PageSeoOverride::find($page);
        abort_if(! $override, 404);

        $entry = $this->entryFor($override);
        abort_if($entry === null, 404);

        return $this->itemResponse($this->rowFor($entry, $override));
    }

    /**
     * title/meta_title/meta_description are the only columns this site has
     * anywhere to put an override — canonical/meta_keywords/in_sitemap ride
     * along in PageList's PUT body but are simply not in the validated set,
     * so they're accepted and ignored rather than rejected.
     */
    public function update(Request $request, int $page): JsonResponse
    {
        $override = PageSeoOverride::find($page);
        abort_if(! $override, 404);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'meta_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'meta_description' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        PageSeoOverrides::apply($override, $data);

        $entry = $this->entryFor($override);
        abort_if($entry === null, 404);

        return $this->itemResponse($this->rowFor($entry, $override->fresh()));
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json([
            'message' => "This site's pages are built into it; ask us to add one.",
        ], 422);
    }

    public function destroy(int $page): JsonResponse
    {
        abort_if(! PageSeoOverride::find($page), 404);

        return response()->json([
            'message' => "This site's pages are built into it; ask us to add one.",
        ], 422);
    }

    /** @return Collection<int, array<string, mixed>> */
    protected function rows(): Collection
    {
        return collect(MarketingPages::all())->map(function (array $entry) {
            $override = PageSeoOverride::findOrCreateFor($entry['route_name'], $entry['params']);

            return $this->rowFor($entry, $override);
        });
    }

    /** @return array<string, mixed> */
    protected function rowFor(array $entry, PageSeoOverride $override): array
    {
        $baseTitle = MarketingPages::baseTitle($entry['route_name'], $entry['params'], $entry['view']);
        $path = MarketingPages::path($entry['route_name'], $entry['params']);
        $mtime = MarketingPages::lastModified($entry['route_name'], $entry['params'], $entry['view']);

        return [
            'id' => $override->id,
            'title' => $override->title ?: $baseTitle,
            'meta_title' => $override->meta_title,
            'meta_description' => $override->meta_description,
            'path' => $path,
            'url' => marketing_url($path),
            'type' => $entry['type'],
            'status' => 'published',
            'in_sitemap' => null, // no per-page sitemap setting here: the admin shows no switch
            'updated_at' => $mtime ? Carbon::createFromTimestamp($mtime)->toIso8601String() : null,
        ];
    }

    /** The live MarketingPages entry a stored override row corresponds to, or null if the route has since disappeared. */
    protected function entryFor(PageSeoOverride $override): ?array
    {
        foreach (MarketingPages::all() as $entry) {
            if ($entry['route_name'] === $override->route_name
                && PageSeoOverride::paramsKey($entry['params']) === $override->params_key) {
                return $entry;
            }
        }

        return null;
    }
}

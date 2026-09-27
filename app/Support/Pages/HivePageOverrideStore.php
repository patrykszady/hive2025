<?php

namespace App\Support\Pages;

use App\Models\PageSeoOverride;
use App\Support\MarketingPages;
use App\Support\PageSeoOverrides;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use SsSystems\Platform\Pages\Contracts\PageOverrideStore;
use SsSystems\Platform\Pages\PageOverrideRefused;

/**
 * ss-systems' Pages screen (App\Livewire\Admin\PageList there), over the
 * marketing site's Blade views instead of database rows — see
 * App\Support\MarketingPages for how those views are enumerated (the exact
 * same source App\Support\MarketingSitemap draws sitemap.xml from, plus the
 * two un-localized legal pages) and App\Models\PageSeoOverride for the one
 * table an edit here actually writes. See the kit's
 * SsSystems\Platform\Pages\Http\Concerns\ServesPages for the shared HTTP
 * shaping (index/types/show/update/store/destroy) this class's
 * PageController now delegates to.
 *
 * Every page always exists, is always "published", and is always in the
 * sitemap — this site has no draft state and no way to exclude a route from
 * MarketingSitemap short of removing it from routes/web.php — so
 * `in_sitemap` always reads true and a PUT's `in_sitemap`/`canonical`/
 * `meta_keywords` (ss-systems' PageList sends all three; Dawn's reference
 * ServiceController/PageController ignore them too when a site has nothing
 * to back them with) are accepted and silently ignored rather than 422ing
 * a request the screen always sends this way — none of those three keys
 * appear in updateRules() at all, so Laravel's validate() simply drops
 * them.
 *
 * No page can be created or removed here: every one of them is code, not
 * data. create()/delete() both throw PageOverrideRefused with a message
 * and no field errors, so the ss.systems screen shows it as a calm notice
 * rather than a red failure.
 *
 * Uses `Collection` filtering/sorting rather than gsc/jpeterson's raw-array
 * + usort — a second, equally valid in-memory execution strategy (see
 * Contracts\PageOverrideStore's own docblock): behaviourally identical,
 * just a different style over the same small, code-enumerated page set.
 */
class HivePageOverrideStore implements PageOverrideStore
{
    public function paginate(array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $rows = $this->rows();

        if ($filters['search'] !== '') {
            $needle = Str::lower($filters['search']);
            $rows = $rows->filter(fn (array $r) => Str::contains(Str::lower($r['title'] ?? ''), $needle)
                || Str::contains(Str::lower($r['path'] ?? ''), $needle));
        }

        if ($filters['type'] !== '') {
            $rows = $rows->where('type', $filters['type']);
        }

        // Every page is always in the sitemap — 'sitemap=0' (not in sitemap)
        // truthfully has nothing to show; null/'1' show everything.
        if ($filters['sitemap'] === '0') {
            $rows = $rows->filter(fn () => false);
        }

        $sortColumn = match ($filters['sort']) {
            'title' => 'title',
            'path' => 'path',
            default => 'updated_at',
        };

        $sorted = ($filters['dir'] === 'asc' ? $rows->sortBy($sortColumn) : $rows->sortByDesc($sortColumn))->values();

        return new LengthAwarePaginator(
            $sorted->slice(($page - 1) * $perPage, $perPage)->values(),
            $sorted->count(),
            $perPage,
            $page
        );
    }

    public function typeCounts(): array
    {
        return $this->rows()
            ->groupBy('type')
            ->map(fn (Collection $group, string $type) => ['type' => $type, 'count' => $group->count()])
            ->sortBy('type')
            ->values()
            ->all();
    }

    public function find(int|string $id): array
    {
        $override = PageSeoOverride::find($id);
        abort_if(! $override, 404);

        $entry = $this->entryFor($override);
        abort_if($entry === null, 404);

        return $this->rowFor($entry, $override);
    }

    /**
     * title/meta_title/meta_description are the only columns this site has
     * anywhere to put an override — canonical/meta_keywords/in_sitemap ride
     * along in PageList's PUT body but are simply not in the validated set,
     * so they're accepted and ignored rather than rejected.
     */
    public function updateRules(int|string $id): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'meta_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'meta_description' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    public function update(int|string $id, array $data): array
    {
        $override = PageSeoOverride::find($id);
        abort_if(! $override, 404);

        PageSeoOverrides::apply($override, $data);

        $entry = $this->entryFor($override);
        abort_if($entry === null, 404);

        return $this->rowFor($entry, $override->fresh());
    }

    public function createRules(): array
    {
        return [];
    }

    public function create(array $data): array
    {
        throw new PageOverrideRefused("This site's pages are built into it; ask us to add one.");
    }

    public function delete(int|string $id): void
    {
        abort_if(! PageSeoOverride::find($id), 404);

        throw new PageOverrideRefused("This site's pages are built into it; ask us to add one.");
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

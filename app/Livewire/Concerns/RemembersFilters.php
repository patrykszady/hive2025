<?php

namespace App\Livewire\Concerns;

use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Url;
use Livewire\Livewire;
use ReflectionProperty;

/**
 * Index pages come back the way they were left, for a while. Their filters
 * are cached per user and company on every render, and a visit whose URL
 * carries none of them (the sidebar link, the back button to a bare
 * /projects) restores them instead of the defaults — until the page has gone
 * unused for REMEMBERED_FILTERS_MINUTES, when it opens on its defaults again
 * (Active on /projects). A link that does carry filters (?client_id=5 from a
 * client page) wins, and becomes what the next bare visit restores.
 *
 * The component lists its filter properties in rememberedFilters(), and
 * returns none while it is embedded in another page (its parent scopes it).
 */
trait RemembersFilters
{
    /** How long a page's filters are kept after it was last used. */
    public const REMEMBERED_FILTERS_MINUTES = 15;

    /** @return list<string> */
    abstract protected function rememberedFilters(): array;

    /**
     * Runs after the component's own mount(), so the restored values replace
     * the defaults it set.
     */
    public function mountRemembersFilters(): void
    {
        $filters = $this->rememberedFilters();

        if ($filters === [] || ! auth()->check() || $this->urlCarriesFilters($filters)) {
            return;
        }

        $saved = Cache::get($this->rememberedFiltersKey());

        if (! is_array($saved)) {
            return;
        }

        foreach ($filters as $property) {
            if (array_key_exists($property, $saved)) {
                $this->{$property} = $saved[$property];
            }
        }
    }

    /**
     * Saves on every render, not only on wire:model updates, so a "Clear
     * filters" action that resets properties directly is kept too.
     */
    public function dehydrateRemembersFilters(): void
    {
        $filters = $this->rememberedFilters();

        if ($filters === [] || ! auth()->check()) {
            return;
        }

        $values = [];
        foreach ($filters as $property) {
            $values[$property] = $this->{$property};
        }

        Cache::put($this->rememberedFiltersKey(), $values, now()->addMinutes(self::REMEMBERED_FILTERS_MINUTES));
    }

    protected function rememberedFiltersKey(): string
    {
        $user = auth()->user();

        return sprintf(
            'index-filters:v2:%d:%d:%d:%s',
            $user->id,
            (int) $user->vendor?->id,
            (int) (bool) $user->is_browsing_as_client,
            static::class,
        );
    }

    /** @param  list<string>  $filters */
    protected function urlCarriesFilters(array $filters): bool
    {
        $query = $this->pageQueryString();

        foreach ($this->rememberedFilterUrlKeys($filters) as $key) {
            if (array_key_exists($key, $query)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The page's own query string. A lazy component mounts in a later
     * Livewire request, whose URL is /livewire/update — the page's address is
     * the Referer then, which is also where Livewire's #[Url] reads it.
     *
     * @return array<string, mixed>
     */
    protected function pageQueryString(): array
    {
        if (! Livewire::isLivewireRequest()) {
            return request()->query();
        }

        parse_str((string) parse_url((string) request()->header('Referer'), PHP_URL_QUERY), $query);

        return $query;
    }

    /**
     * The query-string names of the filters: a #[Url(as: …)] alias, a
     * queryString() entry, or nothing for a filter that never reaches the URL.
     *
     * @param  list<string>  $filters
     * @return list<string>
     */
    protected function rememberedFilterUrlKeys(array $filters): array
    {
        $queryString = method_exists($this, 'queryString') ? (array) $this->queryString() : [];
        $keys = [];

        foreach ($filters as $property) {
            $url = (new ReflectionProperty($this, $property))->getAttributes(Url::class)[0] ?? null;

            if ($url) {
                $keys[] = $url->newInstance()->as ?? $property;

                continue;
            }

            if (array_key_exists($property, $queryString)) {
                $keys[] = is_array($queryString[$property]) ? ($queryString[$property]['as'] ?? $property) : $property;
            } elseif (in_array($property, $queryString, true)) {
                $keys[] = $property;
            }
        }

        return $keys;
    }
}

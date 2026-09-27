<?php

namespace App\Support;

use App\Models\JsErrorState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Groups SsSystems\Platform\Pulse's `jserr` site_events rows into one
 * aggregated error per unique (kind, message, source:line). Ported from
 * dawnsellshomes.com's App\Support\JsErrorGroups (the working contract) —
 * same grouping/resolve/delete semantics, over this app's own `site_events`
 * table (SsSystems\Platform\Pulse\Recorder, bound in
 * App\Providers\AppServiceProvider). See
 * database/migrations/2026_09_27_000200_create_js_error_states_table.php
 * for the resolved/deleted-cutoff bookkeeping this class layers on top of
 * the raw events.
 *
 * There is no per-error ingest here — every call recomputes the groups from
 * `site_events` directly. That is deliberately simple over being fast: the
 * beacon caps jserr rows at 3 per page view client-side (BeaconScript), so a
 * full table scan stays cheap. If that ever stops being true, the fix is a
 * scheduled rollup job writing into js_error_states, not a rewrite of the
 * grouping logic itself.
 */
class JsErrorGroups
{
    /**
     * Every group with at least one occurrence after its own
     * `deleted_before` cutoff, newest last-seen first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function all(): Collection
    {
        $states = JsErrorState::query()->get()->keyBy('signature');
        $groups = self::computeGroups($states);

        self::ensureStateRows($groups, $states);

        return collect($groups)
            ->map(function (array $g) use ($states) {
                $g['browsers'] = array_keys($g['browsers']);
                $g['pages'] = array_keys($g['pages']);

                $state = $states->get($g['signature']);
                $g['id'] = $state?->id;
                $g['resolved_at'] = $state?->resolved_at;
                $g['is_resolved'] = $g['resolved_at'] !== null && $g['resolved_at']->gte($g['last_seen_at']);

                unset($g['signature']);

                return $g;
            })
            ->sortByDesc(fn (array $g) => $g['last_seen_at']->getTimestamp())
            ->values();
    }

    /** One group by its js_error_states id, or null if it has no current occurrence. */
    public static function find(int $id): ?array
    {
        return self::all()->first(fn (array $g) => $g['id'] === $id);
    }

    /** @param  array<string, mixed>  $group */
    public static function toApiArray(array $group): array
    {
        return [
            'id' => $group['id'],
            'kind' => $group['kind'],
            'message' => $group['message'],
            'source' => $group['source'],
            'line' => $group['line'],
            // Never tracked by the beacon.
            'column' => null,
            'stack' => null,
            'page_path' => $group['page_path'],
            'user_agent' => null,
            // Additive beyond a raw user_agent string: this site's beacon
            // only ever tells us the browser FAMILY + mobile flag, not a
            // raw user_agent, so it is surfaced as its own list rather than
            // faked into a single user_agent value. Likewise every page
            // path the group was seen on, not just the latest.
            'browsers' => $group['browsers'],
            'pages' => $group['pages'],
            'occurrences' => $group['occurrences'],
            'is_resolved' => $group['is_resolved'],
            'first_seen_at' => $group['first_seen_at']->toIso8601String(),
            'last_seen_at' => $group['last_seen_at']->toIso8601String(),
            'resolved_at' => optional($group['resolved_at'])->toIso8601String(),
        ];
    }

    /**
     * @param  Collection<string, JsErrorState>  $states  keyed by signature
     * @return array<string, array<string, mixed>> keyed by signature
     */
    protected static function computeGroups(Collection $states): array
    {
        $groups = [];

        DB::table('site_events')
            ->where('event', 'jserr')
            ->orderBy('id')
            ->select(['path', 'meta', 'mobile', 'created_at'])
            ->get()
            ->each(function ($row) use (&$groups, $states) {
                $meta = json_decode((string) $row->meta, true) ?: [];
                $message = isset($meta['m']) && $meta['m'] !== '' ? (string) $meta['m'] : '(no message)';
                // BeaconScript's report() prefixes a rejected-promise message
                // with "promise: " — the only signal this site's beacon gives
                // us to tell the two kinds apart.
                $kind = str_starts_with($message, 'promise: ') ? 'promise' : 'error';
                $location = isset($meta['s']) ? (string) $meta['s'] : '';
                $signature = hash('sha256', $kind.'|'.$message.'|'.$location);

                $createdAt = Carbon::parse($row->created_at);

                $state = $states->get($signature);
                if ($state && $state->deleted_before && $createdAt->lte($state->deleted_before)) {
                    return;
                }

                if (! isset($groups[$signature])) {
                    [$source, $line] = self::splitLocation($location);
                    $groups[$signature] = [
                        'signature' => $signature,
                        'kind' => $kind,
                        'message' => $message,
                        'source' => $source,
                        'line' => $line,
                        'occurrences' => 0,
                        'browsers' => [],
                        'pages' => [],
                        'page_path' => null,
                        'first_seen_at' => $createdAt,
                        'last_seen_at' => $createdAt,
                    ];
                }

                $groups[$signature]['occurrences']++;

                if ($createdAt->lt($groups[$signature]['first_seen_at'])) {
                    $groups[$signature]['first_seen_at'] = $createdAt;
                }

                if ($createdAt->gte($groups[$signature]['last_seen_at'])) {
                    $groups[$signature]['last_seen_at'] = $createdAt;
                    $groups[$signature]['page_path'] = $row->path ? '/'.ltrim((string) $row->path, '/') : null;
                }

                $browser = (string) ($meta['b'] ?? 'other').($row->mobile ? ' mobile' : '');
                $groups[$signature]['browsers'][$browser] = true;

                if ($row->path) {
                    $groups[$signature]['pages']['/'.ltrim((string) $row->path, '/')] = true;
                }
            });

        return $groups;
    }

    /**
     * Every group returned to the API needs a stable integer id up front
     * (the resolve/unresolve/delete routes address a group by id, not by
     * signature) — lazily create its js_error_states row the first time it
     * is seen, rather than requiring a click before one exists.
     *
     * @param  array<string, array<string, mixed>>  $groups
     * @param  Collection<string, JsErrorState>  $states  reloaded in place
     */
    protected static function ensureStateRows(array $groups, Collection &$states): void
    {
        $missing = array_values(array_diff(array_keys($groups), $states->keys()->all()));

        if ($missing === []) {
            return;
        }

        $now = now();
        JsErrorState::query()->insertOrIgnore(array_map(fn (string $signature) => [
            'signature' => $signature,
            'resolved_at' => null,
            'deleted_before' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $missing));

        $states = JsErrorState::query()->get()->keyBy('signature');
    }

    /** @return array{0: ?string, 1: ?int} [source, line] */
    protected static function splitLocation(string $location): array
    {
        if ($location === '') {
            return [null, null];
        }

        if (preg_match('/^(.*):(\d+)$/', $location, $m)) {
            return [$m[1] !== '' ? $m[1] : null, (int) $m[2]];
        }

        return [$location, null];
    }
}

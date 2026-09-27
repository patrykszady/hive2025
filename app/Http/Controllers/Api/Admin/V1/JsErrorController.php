<?php

namespace App\Http\Controllers\Api\Admin\V1;

use SsSystems\Platform\Http\Admin\Concerns\BuildsApiResponses;
use App\Http\Controllers\Controller;
use App\Models\JsErrorState;
use App\Support\JsErrorGroups;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Management API for ss-systems' JS Errors board (Livewire\Admin\
 * JsErrorsBoard / PlatformJsErrors, read through App\Support\
 * JsErrorsRollup) — same endpoints and response shapes as jpeterson-
 * design's/dawnsellshomes' Api\Admin\V1\JsErrorController, but this app has
 * no dedicated ingest table: every row is App\Support\JsErrorGroups' live
 * grouping of SsSystems\Platform\Pulse's `jserr` site_events rows. See that
 * class's docblock for the grouping/resolve/delete semantics.
 */
class JsErrorController extends Controller
{
    use BuildsApiResponses;

    public function index(Request $request): JsonResponse
    {
        $status = $request->string('status')->toString() ?: 'open';
        $kind = $request->string('kind')->toString();

        $groups = JsErrorGroups::all()
            ->when($status === 'open', fn ($rows) => $rows->where('is_resolved', false))
            ->when($status === 'resolved', fn ($rows) => $rows->where('is_resolved', true))
            ->when($kind !== '' && $kind !== 'all', fn ($rows) => $rows->where('kind', $kind))
            ->values();

        $perPage = $this->perPage($request);
        $page = max(1, (int) $request->integer('page', 1));

        $paginator = new LengthAwarePaginator(
            items: $groups->forPage($page, $perPage)->values(),
            total: $groups->count(),
            perPage: $perPage,
            currentPage: $page,
            options: ['path' => $request->url(), 'query' => $request->query()],
        );

        return $this->paginatedResponse($paginator, fn (array $group) => JsErrorGroups::toApiArray($group));
    }

    public function summary(): JsonResponse
    {
        $groups = JsErrorGroups::all();
        $open = $groups->where('is_resolved', false);

        return response()->json([
            'data' => [
                'open' => $open->count(),
                'occurrences' => (int) $open->sum('occurrences'),
                'last_24h' => $groups->where('last_seen_at', '>=', now()->subDay())->count(),
                'resolved' => $groups->where('is_resolved', true)->count(),
            ],
        ]);
    }

    public function resolve(int $jsError): JsonResponse
    {
        JsErrorState::findOrFail($jsError)->update(['resolved_at' => now()]);

        return $this->groupResponse($jsError);
    }

    public function unresolve(int $jsError): JsonResponse
    {
        JsErrorState::findOrFail($jsError)->update(['resolved_at' => null]);

        return $this->groupResponse($jsError);
    }

    public function resolveAll(): JsonResponse
    {
        $open = JsErrorGroups::all()->where('is_resolved', false);
        $now = now();

        JsErrorState::query()->whereIn('id', $open->pluck('id'))->update(['resolved_at' => $now]);

        return response()->json(['data' => ['resolved_count' => $open->count()]]);
    }

    public function destroy(int $jsError): Response
    {
        // A cutoff, not a row delete: JsErrorGroups ignores every
        // occurrence at or before this moment, so the group vanishes from
        // every list until (if ever) a fresh occurrence lands after it —
        // see the js_error_states migration's docblock.
        JsErrorState::findOrFail($jsError)->update(['deleted_before' => now(), 'resolved_at' => null]);

        return response()->noContent();
    }

    protected function groupResponse(int $jsError): JsonResponse
    {
        $group = JsErrorGroups::find($jsError);

        // The state row exists (findOrFail above would have 404'd
        // otherwise) but every occurrence behind it is currently cut off
        // by deleted_before — report the state alone rather than 500ing.
        if ($group === null) {
            $state = JsErrorState::findOrFail($jsError);

            return response()->json(['data' => [
                'id' => $state->id,
                'is_resolved' => $state->resolved_at !== null,
                'resolved_at' => optional($state->resolved_at)->toIso8601String(),
            ]]);
        }

        return response()->json(['data' => JsErrorGroups::toApiArray($group)]);
    }
}

<?php

namespace App\Http\Controllers\Api\Admin\V1\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Shared response/query shaping for every /api/admin/v1 controller, so the
 * envelope stays identical across endpoints. Ported from gsc's/
 * dawnsellshomes' BuildsApiResponses (the working contract) — the Analytics,
 * JS Errors and Leads domains all need it and none of the three existing
 * controllers (Ping/DashboardStats/Platforms/SeoSnapshot) did yet.
 */
trait BuildsApiResponses
{
    /**
     * {"data": [...], "meta": {current_page, per_page, total, last_page}}
     */
    protected function paginatedResponse(LengthAwarePaginator $paginator, callable $transform): JsonResponse
    {
        return response()->json([
            'data' => $paginator->getCollection()->map($transform)->values()->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /** {"data": {...}} */
    protected function itemResponse(array $item, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $item], $status);
    }

    /** Clamp a requested per_page to a sane range. */
    protected function perPage(Request $request, int $default = 20, int $max = 100): int
    {
        return max(1, min($max, (int) $request->integer('per_page', $default)));
    }
}

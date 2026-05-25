<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ProjectZipCountsController extends Controller
{
    /**
     * Return project counts grouped by (zip, city, state).
     *
     * Projects are scoped automatically to the authenticated user's vendor
     * via App\Scopes\ProjectScope. The token used to call this endpoint
     * therefore determines which vendor's projects are returned.
     *
     * Response shape:
     * [
     *   { "zip": "60067", "city": "Palatine", "state": "IL", "count": 105 },
     *   ...
     * ]
     */
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $vendorId = $user?->vendor?->id ?? 0;

        $cacheKey = "api:v1:projects:zip-counts:vendor:{$vendorId}";

        $data = Cache::remember($cacheKey, now()->addHours(1), function () {
            return Project::query()
                ->whereNotNull('zip_code')
                ->where('zip_code', '>', 0)
                ->selectRaw('zip_code, city, state, COUNT(*) as count')
                ->groupBy('zip_code', 'city', 'state')
                ->orderByDesc('count')
                ->get()
                ->map(fn ($row) => [
                    'zip' => str_pad((string) $row->zip_code, 5, '0', STR_PAD_LEFT),
                    'city' => trim((string) $row->city) ?: null,
                    'state' => trim((string) $row->state) ?: null,
                    'count' => (int) $row->count,
                ])
                ->values()
                ->all();
        });

        return response()->json([
            'data' => $data,
            'generated_at' => now()->toIso8601String(),
        ]);
    }
}

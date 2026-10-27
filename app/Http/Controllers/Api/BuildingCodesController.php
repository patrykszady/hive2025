<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\BuildingCodesRequest;
use App\Models\VillageCodes;
use App\Support\Permits\BuildingCodes;
use Illuminate\Http\JsonResponse;

/**
 * A town's adopted building codes, as its own building department publishes
 * them (village_codes, read by App\Services\Permits\VillageCodeFinder and
 * re-read monthly). gs.construction shows them on its town pages
 * (2026-10-27): unique, checkable local content no competitor has.
 *
 * A town nobody has looked up yet starts a lookup in the background and
 * answers "pending"; the site's daily sync picks the codes up later. The two
 * towns typed in by hand (Rolling Meadows, Palatine) answer from that list
 * until their row exists.
 */
class BuildingCodesController extends Controller
{
    public function __invoke(BuildingCodesRequest $request): JsonResponse
    {
        $state = strtoupper((string) ($request->validated('state') ?: 'IL'));
        $town = VillageCodes::normalizeTown((string) $request->validated('town'));

        $row = BuildingCodes::lookup($town, $state) ?? BuildingCodes::row($town, $state);

        if ($row) {
            return response()->json(['data' => [
                'town' => $row->town,
                'state' => $row->state,
                'status' => $row->isFound() ? VillageCodes::STATUS_FOUND : $row->status,
                'codes' => $row->isFound() ? array_values((array) $row->codes) : [],
                'source_url' => $row->isFound() ? $row->source_url : null,
                'note' => $row->isFound() ? $row->note : null,
                'checked_at' => $row->checked_at?->toIso8601String(),
            ]]);
        }

        $known = BuildingCodes::match($town);

        return response()->json(['data' => [
            'town' => $known ?? $town,
            'state' => $state,
            'status' => $known ? VillageCodes::STATUS_FOUND : VillageCodes::STATUS_PENDING,
            'codes' => $known ? BuildingCodes::for($known) : [],
            'source_url' => $known ? BuildingCodes::sourceFor($known) : null,
            'note' => null,
            'checked_at' => null,
        ]]);
    }
}

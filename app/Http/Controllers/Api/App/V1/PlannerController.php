<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\PlannerIndexRequest;
use App\Models\ProjectActionItem;
use App\Services\Planner\AppPlannerAgenda;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The app's Planner tab: the same week agenda, "To schedule" tray, and
 * action items from calls & texts as the web planner — see
 * App\Services\Planner\AppPlannerAgenda for the shared query and shaping,
 * and App\Livewire\Planner\CardsIndex for the web component this mirrors.
 */
class PlannerController extends Controller
{
    public function index(PlannerIndexRequest $request, AppPlannerAgenda $agenda): JsonResponse
    {
        $fromInput = $request->validated('from');
        $toInput = $request->validated('to');

        if (is_string($fromInput) && is_string($toInput)) {
            $from = Carbon::createFromFormat('!Y-m-d', $fromInput, AppPlannerAgenda::TIMEZONE);
            $to = Carbon::createFromFormat('!Y-m-d', $toInput, AppPlannerAgenda::TIMEZONE);
        } else {
            [$from, $to] = $agenda->defaultRange();
        }

        return response()->json(['data' => $agenda->agenda($request->user(), $from, $to)]);
    }

    public function actionItems(Request $request, AppPlannerAgenda $agenda): JsonResponse
    {
        return response()->json(['data' => $agenda->actionItems($request->user())]);
    }

    public function markDone(Request $request, int $item, AppPlannerAgenda $agenda): JsonResponse
    {
        return $this->settle($request, $item, $agenda, ProjectActionItem::STATUS_DONE);
    }

    public function dismiss(Request $request, int $item, AppPlannerAgenda $agenda): JsonResponse
    {
        return $this->settle($request, $item, $agenda, ProjectActionItem::STATUS_DISMISSED);
    }

    private function settle(Request $request, int $item, AppPlannerAgenda $agenda, string $status): JsonResponse
    {
        $found = $agenda->settle($request->user(), $item, $status);
        abort_unless($found, 404);

        return response()->json(['data' => ['id' => $found->id, 'status' => $found->status]]);
    }
}

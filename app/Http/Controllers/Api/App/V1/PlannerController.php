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

        $filters = [
            'search' => trim((string) $request->validated('search', '')),
            'project_ids' => array_map('intval', (array) $request->validated('project_ids', [])),
            'status_codes' => array_map('intval', (array) $request->validated('status_codes', [])),
            'vendor_id' => $request->validated('vendor_id') !== null ? (int) $request->validated('vendor_id') : null,
            'user_ids' => array_map('intval', (array) $request->validated('user_ids', [])),
            'mine' => $request->boolean('mine'),
        ];

        if (is_string($fromInput) && is_string($toInput)) {
            // Legacy explicit range: every day in it shows, same as before —
            // no tri-state filtering and no paging affordance apply to a
            // caller-picked window.
            $from = Carbon::createFromFormat('!Y-m-d', $fromInput, AppPlannerAgenda::TIMEZONE);
            $to = Carbon::createFromFormat('!Y-m-d', $toInput, AppPlannerAgenda::TIMEZONE);
            $when = 'all';
            $daysBack = $from->lessThan($agenda->today()) ? (int) $from->diffInDays($agenda->today()) : 0;
            $daysForward = $to->greaterThan($agenda->today()) ? (int) $agenda->today()->diffInDays($to) : 0;
            $hidePending = false;
            $canLoadEarlier = false;
            $canLoadLater = false;
        } else {
            $when = $request->validated('when') ?? 'upcoming';
            $requestedBack = $request->validated('days_back') !== null ? (int) $request->validated('days_back') : null;
            $requestedForward = $request->validated('days_forward') !== null ? (int) $request->validated('days_forward') : null;

            [$from, $to, $daysBack, $daysForward] = $agenda->resolveWindow($when, $requestedBack, $requestedForward);

            $hidePending = $when === 'past';
            $canLoadEarlier = $when !== 'upcoming' && $daysBack < AppPlannerAgenda::MAX_WINDOW_DAYS;
            $canLoadLater = $when !== 'past' && $daysForward < AppPlannerAgenda::MAX_WINDOW_DAYS;
        }

        $data = $agenda->agenda($request->user(), $from, $to, $filters, $hidePending);

        return response()->json(['data' => $data + [
            'when' => $when,
            'days_back' => $daysBack,
            'days_forward' => $daysForward,
            'can_load_earlier' => $canLoadEarlier,
            'can_load_later' => $canLoadLater,
        ]]);
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

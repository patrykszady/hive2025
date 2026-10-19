<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\StoreDistributionRequest;
use App\Http\Resources\Api\App\DistributionResource;
use App\Models\Distribution;
use App\Models\User;
use App\Services\Distributions\DistributionFigures;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The app's Distributions tab — the same balance table, vendor breakdown
 * and create flow as the web's App\Livewire\Distributions\{DistributionsList,
 * DistributionsShow,DistributionCreate}. Assigning a project's profit
 * split across distributions (DistributionProjectsForm/Table) is a desktop
 * bookkeeping task, not something this mirrors — it stays web-only.
 */
class DistributionController extends Controller
{
    private const PROJECTS_PER_PAGE = 10;

    public function index(DistributionFigures $figures): JsonResponse
    {
        $this->authorize('viewAny', Distribution::class);

        return response()->json([
            'data' => $figures->ytdTable()->map(
                fn (Distribution $distribution) => (new DistributionResource($distribution))->toArray(request())
            )->values()->all(),
        ]);
    }

    public function show(Request $request, int $distribution, DistributionFigures $figures): JsonResponse
    {
        $found = Distribution::query()->whereKey($distribution)->first();
        abort_unless($found, 404);

        $this->authorize('view', $found);

        $start = today()->subYear()->format('Y-m-d');
        $end = today()->format('Y-m-d');
        $page = max(1, (int) $request->query('page', 1));

        $breakdown = $figures->detail($found, $start, $end, $page, self::PROJECTS_PER_PAGE);

        return response()->json([
            'data' => (new DistributionResource($found))->withBreakdown($breakdown)->toArray($request),
            'projects' => [
                'data' => $breakdown['projects']->map(fn ($project) => [
                    'id' => $project->id,
                    'name' => $project->project_name,
                    'address' => $project->short_address,
                    'amount' => round((float) $project->pivot->amount, 2),
                    'percent' => (int) $project->pivot->percent,
                ])->all(),
                'current_page' => $breakdown['projects']->currentPage(),
                'last_page' => $breakdown['projects']->lastPage(),
                'total' => $breakdown['projects']->total(),
            ],
        ]);
    }

    /**
     * Employed, Admin-tier team members with no distribution of their own
     * yet — the candidates for the "new distribution" form's picker, same
     * as DistributionCreate::mount().
     */
    public function eligibleUsers(Request $request, DistributionFigures $figures): JsonResponse
    {
        $this->authorize('viewAny', Distribution::class);

        $vendor = $request->user()->vendor;

        return response()->json([
            'data' => $figures->eligibleUsers($vendor)->map(fn (User $user) => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'nickname' => $user->nickname,
            ])->values()->all(),
        ]);
    }

    public function store(StoreDistributionRequest $request, DistributionFigures $figures): JsonResponse
    {
        $this->authorize('viewAny', Distribution::class);

        $distribution = $figures->create(
            $request->user()->vendor,
            (int) $request->validated('user_id'),
            (string) $request->validated('name'),
        );

        return response()->json([
            'data' => (new DistributionResource($distribution))->toArray($request),
        ], 201);
    }
}

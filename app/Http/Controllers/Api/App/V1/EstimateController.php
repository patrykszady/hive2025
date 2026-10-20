<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\DuplicateEstimateRequest;
use App\Http\Requests\Api\App\StoreEstimateRequest;
use App\Http\Resources\Api\App\EstimateResource;
use App\Models\Estimate;
use App\Models\Project;
use App\Services\Estimates\EstimateDuplicator;
use App\Services\Estimates\EstimateSectionWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * The app's Estimates tab — Admin-only and not for a 1099 vendor, exactly
 * like the web: EstimatePolicy's viewAny/view/update all require
 * vendor_role === 'Admin' and business_type !== '1099' (the
 * is_browsing_as_client branch never applies here, since the Hive
 * Contractors app is GS Construction's own team). See
 * App\Livewire\Estimates\EstimatesIndex, EstimateShow, EstimateCreate, and
 * the Estimates services this mirrors (EstimateSectionWriter,
 * EstimateDuplicator) for the business rules shared with the web.
 */
class EstimateController extends Controller
{
    private const PER_PAGE = 15;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Estimate::class);

        $projectId = $request->query('project_id');

        $query = Estimate::withTrashed()
            ->with(['estimate_sections:id,estimate_id,total', 'project.client'])
            ->when(is_numeric($projectId), fn ($q) => $q->where('project_id', (int) $projectId))
            ->orderBy('deleted_at')
            ->orderByDesc('created_at');

        $page = $query->paginate(self::PER_PAGE);

        return response()->json([
            'data' => $page->getCollection()->map(fn (Estimate $estimate) => (new EstimateResource($estimate))->toArray($request))->all(),
            'meta' => [
                'total' => $page->total(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
            ],
        ]);
    }

    public function show(Request $request, int $estimate): JsonResponse
    {
        $found = $this->visibleEstimate($estimate);
        $this->authorize('view', $found);

        $found->load(['estimate_sections.estimate_line_items.allowances', 'project.client']);

        return response()->json(['data' => (new EstimateResource($found))->withDetail()->toArray($request)]);
    }

    public function store(StoreEstimateRequest $request): JsonResponse
    {
        $project = Project::query()->findOrFail($request->validated('project_id'));
        $this->authorize('create', [Estimate::class, $project]);

        $estimate = Estimate::create([
            'project_id' => $project->id,
            'belongs_to_vendor_id' => $request->user()->vendor->id,
        ]);

        // Mirrors App\Livewire\Estimates\EstimateShow::mount()'s auto-scaffold
        // of a first empty section whenever a fresh estimate has none.
        app(EstimateSectionWriter::class)->addSection($estimate);

        $estimate->load(['estimate_sections.estimate_line_items.allowances', 'project.client']);

        return response()->json(['data' => (new EstimateResource($estimate))->withDetail()->toArray($request)], 201);
    }

    public function destroy(Request $request, int $estimate): JsonResponse
    {
        $found = $this->visibleEstimate($estimate);
        $this->authorize('delete', $found);

        $found->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function restore(Request $request, int $estimate): JsonResponse
    {
        $found = $this->visibleEstimate($estimate, withTrashed: true);
        $this->authorize('restore', $found);

        $found->restore();

        return response()->json(['data' => (new EstimateResource($found->fresh()))->toArray($request)]);
    }

    public function duplicate(DuplicateEstimateRequest $request, int $estimate): JsonResponse
    {
        $source = $this->visibleEstimate($estimate);

        $projectId = $request->validated('project_id') ?? $source->project_id;
        $project = Project::query()->findOrFail($projectId);
        $this->authorize('create', [Estimate::class, $project]);

        $duplicated = app(EstimateDuplicator::class)->duplicateEstimate(
            $source,
            $project,
            $request->validated('cost_mode') ?? 'exact',
            (bool) $request->boolean('include_allowances', true),
        );

        $duplicated->load(['estimate_sections.estimate_line_items.allowances', 'project.client']);

        return response()->json(['data' => (new EstimateResource($duplicated))->withDetail()->toArray($request)], 201);
    }

    /**
     * A fresh, time-limited signed link to this estimate's PDF — same
     * App\Support\EstimateDocumentGenerator output App\Livewire\Estimates\
     * EstimateShow::create_pdf() streams on the web, but reachable without a
     * web session (the `signed` middleware alone is the gate — see
     * routes/web.php's estimates.pdf.signed and EstimateController::pdf()).
     */
    public function pdfUrl(Request $request, int $estimate): JsonResponse
    {
        $found = $this->visibleEstimate($estimate);
        $this->authorize('view', $found);

        $expiresAt = now()->addMinutes(10);

        return response()->json([
            'data' => [
                'url' => URL::temporarySignedRoute('estimates.pdf.signed', $expiresAt, ['estimate' => $found->id]),
                'expires_at' => $expiresAt->toIso8601String(),
            ],
        ]);
    }

    private function visibleEstimate(int $id, bool $withTrashed = false): Estimate
    {
        $existsQuery = Estimate::withoutGlobalScopes();

        if (! $withTrashed) {
            $existsQuery->whereNull('deleted_at');
        }

        abort_unless($existsQuery->whereKey($id)->exists(), 404);

        $scopedQuery = $withTrashed ? Estimate::withTrashed() : Estimate::query();
        $found = $scopedQuery->whereKey($id)->first();

        abort_unless($found, 403, 'You do not have access to this estimate.');

        return $found;
    }
}

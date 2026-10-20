<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\DuplicateEstimateSectionToEstimateRequest;
use App\Http\Requests\Api\App\StoreEstimateSectionRequest;
use App\Http\Requests\Api\App\UpdateEstimateSectionRequest;
use App\Models\Estimate;
use App\Models\EstimateSection;
use App\Services\Estimates\EstimateDuplicator;
use App\Services\Estimates\EstimateSectionWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * An estimate's sections — mirrors App\Livewire\Estimates\EstimateShow's
 * sectionAdd/sectionUpdate/sectionDelete/sectionRestore exactly via
 * App\Services\Estimates\EstimateSectionWriter, the service both surfaces
 * share.
 */
class EstimateSectionController extends Controller
{
    public function store(StoreEstimateSectionRequest $request, int $estimate): JsonResponse
    {
        $found = $this->visibleEstimate($estimate);
        $this->authorize('update', $found);

        $section = app(EstimateSectionWriter::class)->addSection($found, $request->validated('name'));

        return response()->json(['data' => $this->sectionArray($section)], 201);
    }

    public function update(UpdateEstimateSectionRequest $request, int $estimate, int $section): JsonResponse
    {
        $found = $this->visibleEstimate($estimate);
        $this->authorize('update', $found);

        $sectionModel = $found->estimate_sections()->findOrFail($section);
        $sectionModel = app(EstimateSectionWriter::class)->renameSection($sectionModel, $request->validated('name'));

        return response()->json(['data' => $this->sectionArray($sectionModel)]);
    }

    public function destroy(Request $request, int $estimate, int $section): JsonResponse
    {
        $found = $this->visibleEstimate($estimate);
        $this->authorize('update', $found);

        $sectionModel = $found->estimate_sections()->findOrFail($section);
        app(EstimateSectionWriter::class)->deleteSection($sectionModel);

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function restore(Request $request, int $estimate, int $section): JsonResponse
    {
        $found = $this->visibleEstimate($estimate);
        $this->authorize('update', $found);

        $sectionModel = $found->estimate_sections()->withTrashed()->findOrFail($section);
        $sectionModel = app(EstimateSectionWriter::class)->restoreSection($sectionModel);

        return response()->json(['data' => $this->sectionArray($sectionModel->fresh())]);
    }

    /** Mirrors App\Livewire\Estimates\EstimateShow::sectionDuplicate() — an exact in-place copy, named " -Copy". */
    public function duplicate(Request $request, int $estimate, int $section): JsonResponse
    {
        $found = $this->visibleEstimate($estimate);
        $this->authorize('update', $found);

        $sectionModel = $found->estimate_sections()->findOrFail($section);
        $newSection = app(EstimateSectionWriter::class)->duplicateSection($sectionModel);

        return response()->json(['data' => $this->sectionArray($newSection->fresh())], 201);
    }

    /**
     * The other estimates in this section's project — mirrors
     * App\Livewire\Estimates\EstimateDuplicate::duplicateToEstimateModal()'s
     * own `$this->estimates` list, for the "duplicate to another estimate"
     * target picker.
     */
    public function duplicateTargets(Request $request, int $estimate, int $section): JsonResponse
    {
        $found = $this->visibleEstimate($estimate);
        $this->authorize('update', $found);

        $found->estimate_sections()->findOrFail($section);

        $targets = Estimate::where('project_id', $found->project_id)
            ->where('id', '!=', $found->id)
            ->with('estimate_sections:id,estimate_id,total')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => $targets->map(fn (Estimate $e) => [
            'id' => $e->id,
            'number' => $e->number,
            'sections_count' => $e->estimate_sections->count(),
            'total' => round((float) $e->estimate_sections->sum('total'), 2),
            'created_at' => $e->created_at?->toIso8601String(),
        ])->values()->all()]);
    }

    /**
     * Duplicates this section onto ANOTHER estimate in the same project —
     * mirrors App\Livewire\Estimates\EstimateDuplicate::duplicateToEstimateModal()
     * + save_section() via App\Services\Estimates\EstimateDuplicator, the
     * same service App\Http\Controllers\Api\App\V1\EstimateController::duplicate()
     * uses for whole-estimate duplication. A null target_estimate_id means
     * "a brand new estimate" (the web's estimate_id === 'new').
     */
    public function duplicateToEstimate(DuplicateEstimateSectionToEstimateRequest $request, int $estimate, int $section): JsonResponse
    {
        $found = $this->visibleEstimate($estimate);
        $this->authorize('update', $found);

        $sectionModel = $found->estimate_sections()->findOrFail($section);
        $project = $found->project;

        // Same gate save_section() applies to the target project.
        $this->authorize('create', [Estimate::class, $project]);

        $targetEstimateId = $request->validated('target_estimate_id');

        if ($targetEstimateId !== null) {
            if ((int) $targetEstimateId === $found->id) {
                return response()->json(['message' => 'Cannot duplicate section to the same estimate. Please select a different estimate.'], 422);
            }

            $targetEstimate = Estimate::query()->where('project_id', $project->id)->find($targetEstimateId);
            abort_unless($targetEstimate, 404, 'Target estimate not found in this project.');
        } else {
            $targetEstimate = Estimate::create([
                'project_id' => $project->id,
                'belongs_to_vendor_id' => auth()->user()->vendor->id,
            ]);
        }

        $newSection = app(EstimateDuplicator::class)->duplicateSection(
            $sectionModel,
            $targetEstimate,
            $request->validated('cost_mode') ?? 'exact',
            $request->boolean('include_allowances', true),
        );

        return response()->json(['data' => [
            'target_estimate_id' => $targetEstimate->id,
            'section' => $this->sectionArray($newSection->fresh()),
        ]], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function sectionArray(EstimateSection $section): array
    {
        return [
            'id' => $section->id,
            'name' => $section->name,
            'order' => $section->order,
            'total' => round((float) $section->total, 2),
            'locked' => $section->isLocked(),
        ];
    }

    private function visibleEstimate(int $id): Estimate
    {
        abort_unless(Estimate::withoutGlobalScopes()->whereKey($id)->exists(), 404);
        $found = Estimate::query()->whereKey($id)->first();
        abort_unless($found, 403, 'You do not have access to this estimate.');

        return $found;
    }
}

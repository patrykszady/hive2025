<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\StoreEstimateSectionRequest;
use App\Http\Requests\Api\App\UpdateEstimateSectionRequest;
use App\Models\Estimate;
use App\Models\EstimateSection;
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

<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\EstimateMarkupRequest;
use App\Models\Estimate;
use App\Models\EstimateSection;
use App\Services\Estimates\EstimateMarkupApplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * An estimate section's — or the whole estimate's — "Mark Up Line Items" /
 * "Revert Last Markup" (its menu, on the web). Mirrors
 * App\Livewire\Estimates\EstimateMarkup exactly via
 * App\Services\Estimates\EstimateMarkupApplier, the service both surfaces
 * share: never duplicated here.
 */
class EstimateMarkupController extends Controller
{
    /** What a markup would do, without saving anything — the dialog's live preview. */
    public function preview(EstimateMarkupRequest $request, int $estimate, EstimateMarkupApplier $applier): JsonResponse
    {
        $found = $this->visibleEstimate($estimate);
        $this->authorize('update', $found);

        $sectionId = $request->validated('section_id') !== null ? (int) $request->validated('section_id') : null;

        return response()->json(['data' => $applier->preview($found, (float) $request->validated('percent'), $sectionId)]);
    }

    /**
     * The newest markup still revertible — the "Revert Last Markup" dialog's
     * own "Last markup" line (EstimateMarkup::lastMarkup()). Null data when
     * there is nothing to revert.
     */
    public function last(Request $request, int $estimate, EstimateMarkupApplier $applier): JsonResponse
    {
        $found = $this->visibleEstimate($estimate);
        $this->authorize('update', $found);

        $markup = $applier->latestRevertible($found);
        if ($markup === null) {
            return response()->json(['data' => null]);
        }

        $section = $markup['section_id'] ? EstimateSection::query()->find($markup['section_id']) : null;

        return response()->json(['data' => [
            'percent' => (float) $markup['percent'],
            // Null section_id/section_name together means "every section" —
            // left for the app to word, rather than sending a pre-rendered
            // "every section" string.
            'section_id' => $markup['section_id'],
            'section_name' => $section ? ($section->name ?: 'Unnamed Section') : null,
            'at' => (string) $markup['at'],
            'before' => (float) $markup['before'],
            'after' => (float) $markup['after'],
        ]]);
    }

    public function apply(EstimateMarkupRequest $request, int $estimate, EstimateMarkupApplier $applier): JsonResponse
    {
        $found = $this->visibleEstimate($estimate);
        $this->authorize('update', $found);

        $sectionId = $request->validated('section_id') !== null ? (int) $request->validated('section_id') : null;
        $result = $applier->apply($found, (float) $request->validated('percent'), (int) $request->user()->id, $sectionId);

        return response()->json(['data' => $result]);
    }

    public function revert(Request $request, int $estimate, EstimateMarkupApplier $applier): JsonResponse
    {
        $found = $this->visibleEstimate($estimate);
        $this->authorize('update', $found);

        $result = $applier->revert($found, (int) $request->user()->id);

        return response()->json(['data' => $result]);
    }

    /** An estimate the person may see: 404 when it doesn't exist anywhere, 403 when invisible to this tenant. */
    private function visibleEstimate(int $id): Estimate
    {
        abort_unless(Estimate::withoutGlobalScopes()->whereKey($id)->exists(), 404);
        $found = Estimate::query()->whereKey($id)->first();
        abort_unless($found, 403, 'You do not have access to this estimate.');

        return $found;
    }
}

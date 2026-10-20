<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\StoreEstimateAiRuleRequest;
use App\Models\Estimate;
use App\Models\EstimateAiRule;
use App\Services\Estimates\EstimateAiRuleWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A vendor's "how we estimate" rules — read on every AI draft, regardless
 * of which estimate it's drafted from. Mirrors App\Livewire\Estimates\
 * EstimateAIGenerator's addRule()/approveRule()/dismissRule()/deleteRule()
 * exactly via App\Services\Estimates\EstimateAiRuleWriter, the service both
 * surfaces share. Routed under an estimate (same as the web's generator
 * modal, which is opened from one) purely so the same 'update' gate on that
 * estimate applies — the rules themselves are vendor-wide, not per-estimate.
 */
class EstimateAiRuleController extends Controller
{
    public function index(Request $request, int $estimate): JsonResponse
    {
        $found = $this->visibleEstimate($estimate);
        $this->authorize('update', $found);

        $rules = EstimateAiRule::query()
            ->forVendor((int) ($found->belongs_to_vendor_id ?? 1))
            ->whereIn('status', [EstimateAiRule::ACTIVE, EstimateAiRule::PROPOSED])
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $rules->map(fn (EstimateAiRule $rule) => $this->ruleArray($rule))->values()->all()]);
    }

    public function store(StoreEstimateAiRuleRequest $request, int $estimate, EstimateAiRuleWriter $writer): JsonResponse
    {
        $found = $this->visibleEstimate($estimate);
        $this->authorize('update', $found);

        $rule = $writer->add((int) ($found->belongs_to_vendor_id ?? 1), $request->validated('text'), $request->user()->id);

        return response()->json(['data' => $this->ruleArray($rule)], 201);
    }

    public function approve(Request $request, int $estimate, int $rule, EstimateAiRuleWriter $writer): JsonResponse
    {
        $found = $this->visibleEstimate($estimate);
        $this->authorize('update', $found);

        $ruleModel = $writer->forVendor((int) ($found->belongs_to_vendor_id ?? 1), $rule);
        abort_unless($ruleModel, 404);

        return response()->json(['data' => $this->ruleArray($writer->approve($ruleModel))]);
    }

    public function dismiss(Request $request, int $estimate, int $rule, EstimateAiRuleWriter $writer): JsonResponse
    {
        $found = $this->visibleEstimate($estimate);
        $this->authorize('update', $found);

        $ruleModel = $writer->forVendor((int) ($found->belongs_to_vendor_id ?? 1), $rule);
        abort_unless($ruleModel, 404);

        return response()->json(['data' => $this->ruleArray($writer->dismiss($ruleModel))]);
    }

    public function destroy(Request $request, int $estimate, int $rule, EstimateAiRuleWriter $writer): JsonResponse
    {
        $found = $this->visibleEstimate($estimate);
        $this->authorize('update', $found);

        $ruleModel = $writer->forVendor((int) ($found->belongs_to_vendor_id ?? 1), $rule);
        abort_unless($ruleModel, 404);

        $writer->delete($ruleModel);

        return response()->json(['data' => ['deleted' => true]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function ruleArray(EstimateAiRule $rule): array
    {
        return [
            'id' => $rule->id,
            'text' => $rule->text,
            'status' => $rule->status,
            'source' => $rule->source,
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

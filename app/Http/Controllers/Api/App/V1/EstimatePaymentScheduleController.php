<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\UpdateEstimatePaymentScheduleRequest;
use App\Models\Estimate;
use App\Services\Estimates\EstimatePaymentScheduler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * An estimate's payment schedule and contract dates — mirrors the
 * payments/dates slice of App\Livewire\Estimates\EstimateAccept's "Settings"
 * modal via App\Services\Estimates\EstimatePaymentScheduler, the service both
 * surfaces share. Required contract signer selection, contract template
 * choice and manually reassigning a section's bid stay web-only — see that
 * service's docblock.
 */
class EstimatePaymentScheduleController extends Controller
{
    /**
     * Everything the app's payment-schedule editor needs to prefill: the
     * current payments/dates, the original contract total, and past payment
     * descriptions for the autocomplete.
     */
    public function show(Request $request, int $estimate, EstimatePaymentScheduler $scheduler): JsonResponse
    {
        $found = $this->visibleEstimate($estimate);
        $this->authorize('view', $found);

        return response()->json([
            'data' => [
                'payments' => $found->payments ?? [],
                'start_date' => $found->start_date?->toDateString(),
                'end_date' => $found->end_date?->toDateString(),
                'contract_total' => round($scheduler->originalContractTotal($found), 2),
                'description_suggestions' => $scheduler->uniqueDescriptions($found),
            ],
        ]);
    }

    public function update(UpdateEstimatePaymentScheduleRequest $request, int $estimate, EstimatePaymentScheduler $scheduler): JsonResponse
    {
        $found = $this->visibleEstimate($estimate);
        $this->authorize('update', $found);

        try {
            $result = $scheduler->save(
                $found,
                $request->validated('payments') ?? [],
                $request->validated('start_date'),
                $request->validated('end_date'),
            );
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->validator->errors()->first('payments')], 422);
        }

        return response()->json([
            'data' => [
                'payments' => $result['payments'],
                'payments_outstanding' => $result['payments_outstanding'],
                'start_date' => $found->fresh()->start_date?->toDateString(),
                'end_date' => $found->fresh()->end_date?->toDateString(),
            ],
        ]);
    }

    private function visibleEstimate(int $id): Estimate
    {
        abort_unless(Estimate::withoutGlobalScopes()->whereKey($id)->exists(), 404);
        $found = Estimate::query()->whereKey($id)->first();
        abort_unless($found, 403, 'You do not have access to this estimate.');

        return $found;
    }
}

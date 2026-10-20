<?php

namespace App\Services\Estimates;

use App\Models\EstimateAiRule;

/**
 * Adds, approves, dismisses and deletes a company's "how we estimate"
 * rules — the shared body of App\Livewire\Estimates\EstimateAIGenerator's
 * addRule()/approveRule()/dismissRule()/deleteRule() and the Hive
 * Contractors app's EstimateAiRuleController, so both surfaces keep the
 * same rules (read by every AI draft, regardless of which estimate it was
 * drafted from — rules are vendor-wide, not per-estimate).
 */
class EstimateAiRuleWriter
{
    public function add(int $vendorId, string $text, ?int $userId): EstimateAiRule
    {
        return EstimateAiRule::create([
            'vendor_id' => $vendorId,
            'text' => trim($text),
            'status' => EstimateAiRule::ACTIVE,
            'source' => EstimateAiRule::MANUAL,
            'created_by' => $userId,
        ]);
    }

    public function approve(EstimateAiRule $rule): EstimateAiRule
    {
        $rule->update(['status' => EstimateAiRule::ACTIVE]);

        return $rule;
    }

    public function dismiss(EstimateAiRule $rule): EstimateAiRule
    {
        $rule->update(['status' => EstimateAiRule::DISMISSED]);

        return $rule;
    }

    /**
     * A proposal that was approved and then deleted is not proposed again
     * (fingerprint links it back to the pattern that proposed it) — it is
     * dismissed instead. A manually added rule has no fingerprint and is
     * deleted outright.
     */
    public function delete(EstimateAiRule $rule): void
    {
        $rule->fingerprint ? $rule->update(['status' => EstimateAiRule::DISMISSED]) : $rule->delete();
    }

    public function forVendor(int $vendorId, int $ruleId): ?EstimateAiRule
    {
        return EstimateAiRule::query()->forVendor($vendorId)->find($ruleId);
    }
}

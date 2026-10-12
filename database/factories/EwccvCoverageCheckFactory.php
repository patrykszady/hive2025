<?php

namespace Database\Factories;

use App\Models\EwccvCoverageCheck;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EwccvCoverageCheck>
 */
class EwccvCoverageCheckFactory extends Factory
{
    public function definition(): array
    {
        return [
            'vendor_id' => Vendor::factory(),
            'belongs_to_vendor_id' => 1,
            'coverage_date' => now()->subMonth()->toDateString(),
            'status' => EwccvCoverageCheck::STATUS_PENDING,
            'requested_at' => now(),
        ];
    }

    public function covered(string $policy = 'WC-1', string $employer = 'ACME INC'): static
    {
        return $this->state(fn () => ['status' => EwccvCoverageCheck::STATUS_COVERED, 'policy_number' => $policy, 'employer_name' => $employer, 'match_type' => 'policyNumber', 'checked_at' => now()]);
    }

    public function notFound(string $reason = 'no_results'): static
    {
        return $this->state(fn () => ['status' => EwccvCoverageCheck::STATUS_NOT_FOUND, 'reason' => $reason, 'checked_at' => now()]);
    }
}

<?php

namespace Database\Factories;

use App\Models\AuditRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditRequest>
 */
class AuditRequestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'belongs_to_vendor_id' => 1,
            'signature' => AuditRequest::signatureFor('2025-01-01', '2025-12-31', 'workers', [1]),
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
            'audit_type' => 'workers',
            'bank_account_ids' => [1],
            'coverage_date' => '2025-12-29',
            'status' => AuditRequest::STATUS_PENDING,
        ];
    }
}

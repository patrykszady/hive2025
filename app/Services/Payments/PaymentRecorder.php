<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Models\Project;
use App\Models\User;

/**
 * Records a client payment across one or more projects, grouping every row
 * past the first under the first's id via parent_client_payment_id.
 *
 * Extracted from App\Livewire\Forms\PaymentForm::store() (which now calls
 * here) so the Hive Contractors app's payments API
 * (App\Http\Controllers\Api\App\V1\PaymentController::store()) shares the
 * exact same creation logic and side effects (PaymentObserver fires off
 * Payment::create() either way) rather than re-deriving them.
 *
 * Each row in $projectAmounts is ['id' => project id, 'amount' => raw amount
 * (string|float|null)]. A row is dropped — never creates a Payment — when
 * its amount parses to 0.0/blank, or its project id isn't one Project::query()
 * (ProjectScope) can see. Returns null when every row was dropped, so the
 * caller can tell "nothing to store" apart from a real save.
 */
class PaymentRecorder
{
    public function record(User $user, array $projectAmounts, string $date, string $reference, ?string $note): ?Payment
    {
        $projects = collect($projectAmounts)
            ->filter(function ($project) {
                return is_array($project)
                    && $this->parseAmount($project['amount'] ?? null) !== 0.0
                    && $this->isProjectAccessible($project['id'] ?? null);
            })
            ->values();

        if ($projects->isEmpty()) {
            return null;
        }

        $parentPaymentId = null;
        $lastPayment = null;

        foreach ($projects as $index => $project) {
            $lastPayment = Payment::create([
                'amount' => $this->parseAmount($project['amount'] ?? null),
                'project_id' => $project['id'],
                'date' => $date,
                'reference' => $reference,
                'belongs_to_vendor_id' => $user->vendor->id,
                'note' => $note,
                'created_by_user_id' => $user->id,
                'parent_client_payment_id' => $parentPaymentId,
            ]);

            if ($index === 0) {
                $parentPaymentId = $lastPayment->id;
            }
        }

        return $lastPayment;
    }

    /**
     * project_id rides in on a caller-controlled array — check it against
     * what this tenant can see (ProjectScope) rather than trusting it, so a
     * payment can't be recorded against a project outside the tenant.
     */
    public function isProjectAccessible(mixed $projectId): bool
    {
        return $projectId && Project::whereKey($projectId)->exists();
    }

    public function parseAmount(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        $clean = preg_replace('/[^0-9.\-]/', '', (string) $value);
        if ($clean === null || $clean === '' || $clean === '-' || $clean === '.') {
            return 0.0;
        }

        return (float) $clean;
    }
}

<?php

namespace App\Support;

use App\Enums\LienWaiverStatus;
use App\Enums\LienWaiverType;
use App\Models\Check;
use App\Models\LienWaiver;
use App\Models\Payment;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * Domain service that decides if/when/how to create a LienWaiver record for a
 * payment. Single source of truth for the auto-generation rules so it can be
 * triggered from observers, jobs, controllers, or backfill commands.
 */
class LienWaiverFactory
{
    /**
     * Idempotently produce a draft LienWaiver covering the given Payment.
     *
     * Returns null when a waiver should not be created (missing project,
     * negative amount, vendor pays itself, etc.).
     */
    public static function fromPayment(Payment $payment): ?LienWaiver
    {
        $payment->loadMissing(['project.latestStatus']);

        if (! $payment->project_id || ! $payment->belongs_to_vendor_id) {
            return null;
        }

        if ((float) $payment->amount <= 0) {
            return null;
        }

        $check = $payment->check_id
            ? Check::withoutGlobalScopes()->withTrashed()->find($payment->check_id)
            : null;

        $vendorId = $check?->vendor_id;
        if (! $vendorId) {
            return null;
        }

        // Don't create a waiver when a vendor would be issuing it to itself.
        if ((int) $vendorId === (int) $payment->belongs_to_vendor_id) {
            return null;
        }

        // Idempotency: one waiver per (payment_id) -- if a record already
        // exists, reuse it so re-firing observers stays safe.
        $existing = LienWaiver::withoutGlobalScopes()
            ->where('payment_id', $payment->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        $project = $payment->project;
        $type = self::detectType($project, $payment);

        return DB::transaction(function () use ($payment, $check, $vendorId, $project, $type) {
            return LienWaiver::withoutGlobalScopes()->create([
                'belongs_to_vendor_id' => $payment->belongs_to_vendor_id,
                'vendor_id' => $vendorId,
                'project_id' => $project->id,
                'check_id' => $check?->id,
                'payment_id' => $payment->id,
                'type' => $type,
                'status' => LienWaiverStatus::Draft,
                'amount' => $payment->amount,
                'exceptions_amount' => 0,
                'through_date' => $payment->date,
                'jurisdiction' => self::detectJurisdiction($project),
                'created_by_user_id' => $payment->created_by_user_id,
            ]);
        });
    }

    /**
     * Determine the appropriate waiver type for a payment.
     *
     * Rules:
     *  - If the project is Complete, this is a "Final" waiver, otherwise "Progress".
     *  - If the underlying transaction has cleared (transaction_id present on
     *    the check or payment), the waiver is "Unconditional"; otherwise
     *    "Conditional" (i.e. effective upon receipt of payment).
     */
    public static function detectType(Project $project, Payment $payment): LienWaiverType
    {
        $isFinal = (int) ($project->latestStatus?->status_code ?? 0) === 7; // 7 = Complete

        $cleared = ! empty($payment->transaction_id);

        return match (true) {
            $isFinal && $cleared => LienWaiverType::UnconditionalFinal,
            $isFinal && ! $cleared => LienWaiverType::ConditionalFinal,
            ! $isFinal && $cleared => LienWaiverType::UnconditionalProgress,
            default => LienWaiverType::ConditionalProgress,
        };
    }

    /**
     * Pick the statutory form to render. Currently we ship a generic US form
     * for everything; a few states have mandatory or customary wording
     * (CA, AZ, FL, GA, IL, MS, NV, TX, UT, WY) and we surface those when
     * known so the PDF template can branch.
     */
    public static function detectJurisdiction(Project $project): string
    {
        $stateSpecific = ['CA', 'AZ', 'FL', 'GA', 'IL', 'MS', 'NV', 'TX', 'UT', 'WY'];
        $state = strtoupper((string) ($project->state ?? ''));

        return in_array($state, $stateSpecific, true) ? $state : 'US-GENERIC';
    }
}

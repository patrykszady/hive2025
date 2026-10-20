<?php

namespace App\Services\Estimates;

use App\Models\Bid;
use App\Models\Estimate;
use App\Models\ProjectStatus;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Edits an estimate's payment schedule and contract dates — the narrower,
 * most-used slice of App\Livewire\Estimates\EstimateAccept::save() (the
 * "Settings" modal behind the estimate's Options menu). Shared by that
 * component and the Hive Contractors app's EstimatePaymentScheduleController,
 * so both write the exact same payments/dates and project-status side
 * effects.
 *
 * NOT covered here — required contract signer selection, contract template
 * choice, and manually reassigning which bid (original contract vs. a
 * specific change order) a section belongs to — see
 * App\Services\Estimates\EstimateContractSettingsWriter (and the app's
 * EstimateContractSettingsController) for that slice instead. This service
 * only ever touches sections already on the ORIGINAL contract (no bid_id, or
 * the project's type-1 "Original Bid"), which is how every section sits
 * before anyone has manually reassigned one via that other service.
 */
class EstimatePaymentScheduler
{
    /**
     * Payment descriptions previously used on this vendor's estimates, for
     * the describe-this-payment autocomplete — mirrors
     * EstimateAccept::uniquePaymentDescriptions().
     *
     * @return list<string>
     */
    public function uniqueDescriptions(Estimate $estimate): array
    {
        $descriptions = Estimate::withoutGlobalScopes()
            ->where('belongs_to_vendor_id', $estimate->belongs_to_vendor_id)
            ->whereNotNull('options->payments')
            ->pluck('options')
            ->flatMap(fn (array $options) => collect($options['payments'] ?? []))
            ->pluck('description')
            ->filter()
            ->map(fn ($description) => $this->canonicalDescription((string) $description))
            ->filter(fn (string $description) => $description !== '');

        $uniqueByNormalizedValue = [];

        foreach ($descriptions as $description) {
            $normalized = $this->normalizedDescription($description);

            if (! array_key_exists($normalized, $uniqueByNormalizedValue)) {
                $uniqueByNormalizedValue[$normalized] = $description;
            }
        }

        return collect(array_values($uniqueByNormalizedValue))
            ->sort(fn (string $a, string $b) => strcasecmp($a, $b))
            ->values()
            ->all();
    }

    /**
     * The original contract's total — the sections a fresh payment schedule
     * is measured against (see the class docblock on bid scope).
     */
    public function originalContractTotal(Estimate $estimate): float
    {
        $originalBidId = Bid::query()
            ->where('project_id', $estimate->project_id)
            ->where('vendor_id', $estimate->belongs_to_vendor_id)
            ->where('type', 1)
            ->value('id');

        return (float) $estimate->estimate_sections()
            ->where(fn ($query) => $query->whereNull('bid_id')->when($originalBidId, fn ($q) => $q->orWhere('bid_id', $originalBidId)))
            ->sum('total');
    }

    /**
     * @param  list<array{description: string, amount: string|float|null}>  $payments
     * @return array{payments: list<array{description: string, amount: string}>, payments_outstanding: float}
     *
     * @throws ValidationException
     */
    public function save(Estimate $estimate, array $payments, ?string $startDate, ?string $endDate): array
    {
        $canonicalized = collect($payments)
            ->map(fn (array $payment) => [
                'description' => $this->canonicalDescription((string) ($payment['description'] ?? '')),
                'amount' => (string) ($payment['amount'] ?? ''),
            ])
            ->values();

        $this->assertNoDuplicateDescriptions($canonicalized);

        $contractTotal = $this->originalContractTotal($estimate);
        $paymentsSum = $canonicalized->where('amount', '!=', '')->sum(fn ($p) => (float) $p['amount']);
        $outstanding = round($contractTotal - $paymentsSum, 2);

        if ($outstanding < 0) {
            throw ValidationException::withMessages(['payments' => 'Amount Remaining cannot be less than $0.00']);
        }

        $options = $estimate->options ?? [];

        if ($canonicalized->where('amount', '!=', '')->sum(fn ($p) => (float) $p['amount']) != 0) {
            $options['payments'] = $canonicalized->values()->all();
        }

        $options['start_date'] = $startDate;
        $options['end_date'] = $endDate;

        $estimate->options = $options;
        $estimate->save();

        if ($startDate) {
            $this->applyStartDateToProjectStatus($estimate, $startDate);
        }

        return ['payments' => $canonicalized->values()->all(), 'payments_outstanding' => $outstanding];
    }

    /**
     * Auto-transition to Scheduled when a start date is set and the project
     * is still at Response/Prep, or move an already-Scheduled row's date —
     * mirrors EstimateAccept::save()'s side effect exactly.
     */
    protected function applyStartDateToProjectStatus(Estimate $estimate, string $startDate): void
    {
        $project = $estimate->project;
        $latestStatus = $project?->latestStatus;

        if (! $latestStatus) {
            return;
        }

        if (in_array($latestStatus->status_code, [3, 4], true)) {
            ProjectStatus::create([
                'project_id' => $project->id,
                'belongs_to_vendor_id' => $latestStatus->belongs_to_vendor_id,
                'status_code' => 5,
                'start_date' => $startDate,
            ]);
        } elseif ($latestStatus->status_code === 5 && $latestStatus->start_date?->toDateString() !== $startDate) {
            $latestStatus->update(['start_date' => $startDate]);
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array{description: string, amount: string}>  $payments
     *
     * @throws ValidationException
     */
    protected function assertNoDuplicateDescriptions(\Illuminate\Support\Collection $payments): void
    {
        $seen = [];

        foreach ($payments as $payment) {
            $normalized = $this->normalizedDescription($payment['description']);

            if ($normalized === '') {
                continue;
            }

            if (in_array($normalized, $seen, true)) {
                throw ValidationException::withMessages(['payments' => 'Payment descriptions must be unique.']);
            }

            $seen[] = $normalized;
        }
    }

    protected function canonicalDescription(string $description): string
    {
        $normalized = trim($description);
        $normalized = preg_replace('/\s*\/\s*/', '/', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

        return Str::title($normalized);
    }

    protected function normalizedDescription(string $description): string
    {
        return Str::lower($this->canonicalDescription($description));
    }
}

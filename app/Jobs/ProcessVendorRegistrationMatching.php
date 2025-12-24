<?php

namespace App\Jobs;

use App\Models\Bid;
use App\Models\Check;
use App\Models\Client;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\Timesheet;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessVendorRegistrationMatching implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $vendorId, public int $userId)
    {
    }

    public function handle(): void
    {
        $vendor = Vendor::withoutGlobalScopes()->findOrFail($this->vendorId);
        $user = User::find($this->userId);

        $this->updateMatchingStatus($vendor, 'processing');

        try {
            $registeredVendorId = $vendor->id;

            $timesheetUserIds = $this->determineTimesheetUserIds($user);

            $projectIds = $this->determineRelatedProjectIds($registeredVendorId, $timesheetUserIds);
            if (empty($projectIds)) {
                $this->updateMatchingStatus($vendor, 'completed');

                return;
            }

            $projects = Project::withoutGlobalScopes()
                ->whereIn('id', $projectIds)
                ->get();

            foreach ($projects as $project) {
                $client = $this->ensureClientForProjectOwner($project);
                if (! $client) {
                    continue;
                }

                $client->vendors()->syncWithoutDetaching([
                    $registeredVendorId => ['source' => 'belongs_to_vendor'],
                ]);

                $project->vendors()->syncWithoutDetaching([
                    $registeredVendorId => ['client_id' => $client->id],
                ]);

                $this->ensureViewOnlyProjectStatus($project->id, $registeredVendorId, $project->created_at?->format('Y-m-d'));
            }

            $affectedProjectIds = $this->createPaymentsFromChecks($registeredVendorId, $projectIds);
            $this->createBidAdjustmentsIfNeeded($registeredVendorId, $affectedProjectIds);

            $this->updateMatchingStatus($vendor, 'completed');
        } catch (Throwable $e) {
            $this->updateMatchingStatus($vendor, 'failed', $e->getMessage());

            throw $e;
        }
    }

    private function updateMatchingStatus(Vendor $vendor, string $status, ?string $error = null): void
    {
        $registration = $vendor->registration;
        if (is_null($registration)) {
            $registration = [];
        }

        if (! is_array($registration)) {
            $registration = (array) $registration;
        }

        $matching = (array) data_get($registration, 'matching', []);
        $matching['status'] = $status;
        $matching['updated_at'] = now()->toISOString();

        if ($status === 'processing') {
            $matching['started_at'] ??= now()->toISOString();
        }

        if ($status === 'completed') {
            $matching['completed_at'] ??= now()->toISOString();
        }

        if ($status === 'failed') {
            $matching['failed_at'] ??= now()->toISOString();
            $matching['error'] = $error;
        }

        $registration['matching'] = $matching;

        $vendor->forceFill(['registration' => $registration]);
        $vendor->save();
    }

    /**
     * @return array<int>
     */
    private function determineTimesheetUserIds(?User $user): array
    {
        $timesheetUserIds = [$this->userId];

        if ($user && filled($user->email)) {
            $timesheetUserIds = User::query()
                ->whereRaw('LOWER(email) = ?', [mb_strtolower($user->email)])
                ->pluck('id')
                ->all();
        }

        return array_values(array_unique(array_filter($timesheetUserIds)));
    }

    /**
     * @param  array<int>  $timesheetUserIds
     * @return array<int>
     */
    private function determineRelatedProjectIds(int $registeredVendorId, array $timesheetUserIds): array
    {
        $expenseProjectIds = Expense::withoutGlobalScopes()
            ->where('vendor_id', $registeredVendorId)
            ->whereNotNull('project_id')
            ->whereNull('deleted_at')
            ->pluck('project_id')
            ->all();

        $timesheetProjectIds = Timesheet::withoutGlobalScopes()
            ->whereIn('user_id', $timesheetUserIds)
            ->whereNotNull('project_id')
            ->whereNull('deleted_at')
            ->pluck('project_id')
            ->all();

        $projectIds = array_merge($expenseProjectIds, $timesheetProjectIds);
        $projectIds = array_map('intval', $projectIds);

        return array_values(array_unique(array_filter($projectIds)));
    }

    private function ensureClientForProjectOwner(Project $project): ?Client
    {
        $belongsToVendorId = (int) ($project->belongs_to_vendor_id ?? 0);
        if ($belongsToVendorId <= 0) {
            return null;
        }

        $client = Client::withoutGlobalScopes()->where('vendor_id', $belongsToVendorId)->first();
        if ($client) {
            return $client;
        }

        $ownerVendor = Vendor::withoutGlobalScopes()->find($belongsToVendorId);
        if (! $ownerVendor) {
            return null;
        }

        $client = new Client();
        $client->business_name = $ownerVendor->business_name;
        $client->address = $ownerVendor->address;
        $client->address_2 = $ownerVendor->address_2;
        $client->city = $ownerVendor->city;
        $client->state = $ownerVendor->state;
        $client->zip_code = $ownerVendor->zip_code;
        $client->vendor_id = $ownerVendor->id;
        $client->save();

        $adminUsers = $ownerVendor->users()
            ->wherePivot('role_id', 1)
            ->wherePivot('is_employed', true)
            ->get();

        if ($adminUsers->isNotEmpty()) {
            $client->users()->syncWithoutDetaching($adminUsers->pluck('id')->toArray());
        }

        return $client;
    }

    private function ensureViewOnlyProjectStatus(int $projectId, int $registeredVendorId, ?string $startDate): void
    {
        $alreadyExists = ProjectStatus::withoutGlobalScopes()
            ->where('project_id', $projectId)
            ->where('belongs_to_vendor_id', $registeredVendorId)
            ->exists();

        if ($alreadyExists) {
            return;
        }

        $statusCode = ProjectStatus::getCodeForLabel('VIEW ONLY') ?? 11;

        ProjectStatus::create([
            'project_id' => $projectId,
            'belongs_to_vendor_id' => $registeredVendorId,
            'status_code' => $statusCode,
            'start_date' => $startDate ?? today()->format('Y-m-d'),
        ]);
    }

    /**
     * Create vendor payments from existing checks/expenses.
     *
     * Only check-based groups are handled here (idempotent via check_id).
     *
     * @param  array<int>  $projectIds
     * @return array<int> Affected project IDs.
     */
    private function createPaymentsFromChecks(int $registeredVendorId, array $projectIds): array
    {
        $affectedProjectIds = [];

        $checks = Check::withoutGlobalScopes()
            ->where('vendor_id', $registeredVendorId)
            ->whereNull('deleted_at')
            ->get();

        foreach ($checks as $check) {
            $alreadyCreated = Payment::withoutGlobalScopes()
                ->where('check_id', $check->id)
                ->where('belongs_to_vendor_id', $registeredVendorId)
                ->exists();

            if ($alreadyCreated) {
                continue;
            }

            $expenses = Expense::withoutGlobalScopes()
                ->where('check_id', $check->id)
                ->whereNull('deleted_at')
                ->get();

            if ($expenses->isEmpty()) {
                continue;
            }

            $reference = $check->check_type === 'Check'
                ? (string) ($check->check_number ?? $check->id)
                : (string) ($check->check_type ?? $check->id);

            $parentPaymentId = null;

            foreach ($expenses->values() as $index => $expense) {
                $projectId = $expense->project_id ? (int) $expense->project_id : null;
                $distributionId = $projectId ? null : ($expense->distribution_id ? (int) $expense->distribution_id : null);

                if ($projectId && in_array($projectId, $projectIds, true)) {
                    $affectedProjectIds[] = $projectId;

                    $project = Project::withoutGlobalScopes()->find($projectId);
                    if ($project) {
                        $client = $this->ensureClientForProjectOwner($project);
                        if ($client) {
                            $client->vendors()->syncWithoutDetaching([
                                $registeredVendorId => ['source' => 'belongs_to_vendor'],
                            ]);

                            $project->vendors()->syncWithoutDetaching([
                                $registeredVendorId => ['client_id' => $client->id],
                            ]);

                            $this->ensureViewOnlyProjectStatus($project->id, $registeredVendorId, $project->created_at?->format('Y-m-d'));
                        }
                    }
                }

                $payment = Payment::create([
                    'amount' => $expense->amount,
                    'project_id' => $projectId,
                    'distribution_id' => $distributionId,
                    'date' => $expense->date?->format('Y-m-d') ?? today()->format('Y-m-d'),
                    'reference' => $reference,
                    'belongs_to_vendor_id' => $registeredVendorId,
                    'created_by_user_id' => 0,
                    'parent_client_payment_id' => $parentPaymentId,
                    'check_id' => $check->id,
                ]);

                if ($index === 0) {
                    $parentPaymentId = $payment->id;
                }
            }
        }

        $affectedProjectIds = array_values(array_unique(array_filter(array_map('intval', $affectedProjectIds))));

        return $affectedProjectIds;
    }

    /**
     * @param  array<int>  $projectIds
     */
    private function createBidAdjustmentsIfNeeded(int $registeredVendorId, array $projectIds): void
    {
        if (empty($projectIds)) {
            return;
        }

        foreach ($projectIds as $projectId) {
            $projectId = (int) $projectId;
            if ($projectId <= 0) {
                continue;
            }

            $totalBids = (float) Bid::withoutGlobalScopes()
                ->where('project_id', $projectId)
                ->where('vendor_id', $registeredVendorId)
                ->sum('amount');

            $totalPayments = (float) Payment::withoutGlobalScopes()
                ->where('project_id', $projectId)
                ->where('belongs_to_vendor_id', $registeredVendorId)
                ->sum('amount');

            if ($totalPayments <= $totalBids) {
                continue;
            }

            $maxType = (int) (Bid::withoutGlobalScopes()
                ->where('project_id', $projectId)
                ->where('vendor_id', $registeredVendorId)
                ->max('type') ?? 0);

            $nextType = $maxType > 0 ? $maxType + 1 : 1;

            Bid::create([
                'project_id' => $projectId,
                'vendor_id' => $registeredVendorId,
                'amount' => $totalPayments - $totalBids,
                'type' => $nextType,
            ]);
        }
    }
}

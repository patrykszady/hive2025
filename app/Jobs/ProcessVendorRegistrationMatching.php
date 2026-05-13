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
use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
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

        Log::info('[VendorRegistration] Job started', [
            'vendor_id' => $this->vendorId,
            'user_id' => $this->userId,
        ]);

        $this->updateMatchingStatus($vendor, 'processing');

        try {
            $registeredVendorId = $vendor->id;

            $timesheetUserIds = $this->determineTimesheetUserIds($user);

            $projectIds = $this->determineRelatedProjectIds($registeredVendorId, $timesheetUserIds);
            if (empty($projectIds)) {
                Log::info('[VendorRegistration] No related projects found, completing early', [
                    'vendor_id' => $this->vendorId,
                ]);
                $this->updateMatchingStatus($vendor, 'completed');

                return;
            }

            $projects = Project::withoutGlobalScopes()
                ->whereIn('id', $projectIds)
                ->get();

            Log::info('[VendorRegistration] Found projects to process', [
                'vendor_id' => $this->vendorId,
                'project_count' => $projects->count(),
                'project_ids' => $projectIds,
            ]);

            foreach ($projects as $project) {
                // Skip if vendor is already linked to this project with a client
                $existingClientId = DB::table('project_vendor')
                    ->where('project_id', $project->id)
                    ->where('vendor_id', $registeredVendorId)
                    ->whereNotNull('client_id')
                    ->value('client_id');

                if ($existingClientId) {
                    $this->ensureViewOnlyProjectStatus($project->id, $registeredVendorId, $project->created_at?->format('Y-m-d'));

                    continue;
                }

                // Check if the registering vendor IS the project's client
                // (e.g., DK is client on GS-owned projects — DK hired GS)
                $selfClient = $project->client_id
                    ? Client::withoutGlobalScopes()
                        ->where('id', $project->client_id)
                        ->where('vendor_id', $registeredVendorId)
                        ->first()
                    : null;

                if ($selfClient) {
                    $client = $selfClient;
                } else {
                    $client = $this->ensureClientForProjectOwner($project);
                    if (! $client) {
                        continue;
                    }
                }

                $client->vendors()->syncWithoutDetaching([
                    $registeredVendorId => ['source' => 'belongs_to_vendor'],
                ]);

                $project->vendors()->syncWithoutDetaching([
                    $registeredVendorId => ['client_id' => $client->id],
                ]);

                $project->searchable();

                $this->ensureViewOnlyProjectStatus($project->id, $registeredVendorId, $project->created_at?->format('Y-m-d'));
            }

            $affectedProjectIds = $this->createPaymentsFromChecks($registeredVendorId, $projectIds);
            Log::info('[VendorRegistration] Payments from checks created', [
                'vendor_id' => $this->vendorId,
                'affected_projects' => count($affectedProjectIds),
            ]);

            $this->createBidAdjustmentsIfNeeded($registeredVendorId, $affectedProjectIds);

            $this->createExpensesFromOwnerPayments($registeredVendorId, $projects);
            Log::info('[VendorRegistration] Expenses from owner payments created', [
                'vendor_id' => $this->vendorId,
            ]);
            $this->syncVendorsVendor($vendor, $projects);

            Log::info('[VendorRegistration] Data matching done, syncing scout index settings', [
                'vendor_id' => $this->vendorId,
            ]);

            Artisan::call('scout:reindex');

            Log::info('[VendorRegistration] Scout index settings synced', [
                'vendor_id' => $this->vendorId,
            ]);

            $this->updateMatchingStatus($vendor, 'completed');

            Log::info('[VendorRegistration] Job marked completed', [
                'vendor_id' => $this->vendorId,
            ]);
        } catch (Throwable $e) {
            Log::error('[VendorRegistration] Job failed', [
                'vendor_id' => $this->vendorId,
                'error' => $e->getMessage(),
            ]);
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

        $clientLinkedProjectIds = Project::withoutGlobalScopes()
            ->whereHas('client', function ($query) use ($registeredVendorId) {
                $query->withoutGlobalScopes()->where('clients.vendor_id', $registeredVendorId);
            })
            ->whereNull('deleted_at')
            ->pluck('id')
            ->all();

        $projectIds = array_merge($expenseProjectIds, $timesheetProjectIds, $clientLinkedProjectIds);
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

                    // Only ensure client/project link if not already established
                    $existingClientId = DB::table('project_vendor')
                        ->where('project_id', $projectId)
                        ->where('vendor_id', $registeredVendorId)
                        ->whereNotNull('client_id')
                        ->value('client_id');

                    if (! $existingClientId) {
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

                                $project->searchable();

                                $this->ensureViewOnlyProjectStatus($project->id, $registeredVendorId, $project->created_at?->format('Y-m-d'));
                            }
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

        // Handle expenses with no check_id (orphan expenses)
        $orphanExpenses = Expense::withoutGlobalScopes()
            ->where('vendor_id', $registeredVendorId)
            ->whereNull('check_id')
            ->whereIn('project_id', $projectIds)
            ->whereNull('deleted_at')
            ->get();

        foreach ($orphanExpenses as $expense) {
            $alreadyCreated = Payment::withoutGlobalScopes()
                ->where('belongs_to_vendor_id', $registeredVendorId)
                ->where('project_id', $expense->project_id)
                ->where('amount', $expense->amount)
                ->where('date', $expense->date?->format('Y-m-d'))
                ->whereNull('check_id')
                ->exists();

            if ($alreadyCreated) {
                continue;
            }

            $projectId = (int) $expense->project_id;
            $affectedProjectIds[] = $projectId;

            $existingClientId = DB::table('project_vendor')
                ->where('project_id', $projectId)
                ->where('vendor_id', $registeredVendorId)
                ->whereNotNull('client_id')
                ->value('client_id');

            if (! $existingClientId) {
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

                        $project->searchable();

                        $this->ensureViewOnlyProjectStatus($project->id, $registeredVendorId, $project->created_at?->format('Y-m-d'));
                    }
                }
            }

            Payment::create([
                'amount' => $expense->amount,
                'project_id' => $projectId,
                'distribution_id' => null,
                'date' => $expense->date?->format('Y-m-d') ?? today()->format('Y-m-d'),
                'reference' => $expense->invoice ?? 'Expense',
                'belongs_to_vendor_id' => $registeredVendorId,
                'created_by_user_id' => 0,
                'parent_client_payment_id' => null,
                'check_id' => null,
            ]);
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

    /**
     * Create expense records for the registering vendor from the project owner's payments.
     *
     * From the registering vendor's perspective, payments the project owner made
     * to them are expenses (money they received for work performed).
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, Project>  $projects
     */
    private function createExpensesFromOwnerPayments(int $registeredVendorId, $projects): void
    {
        foreach ($projects as $project) {
            $ownerVendorId = (int) ($project->belongs_to_vendor_id ?? 0);
            if ($ownerVendorId <= 0) {
                continue;
            }

            // Only mirror payments for projects where the registering vendor
            // was the client (i.e. they paid the project owner). Skip projects
            // where the registering vendor was just a sub.
            $originalClient = Client::withoutGlobalScopes()->find($project->client_id);
            if (! $originalClient || (int) $originalClient->vendor_id !== $registeredVendorId) {
                continue;
            }

            $ownerPayments = Payment::withoutGlobalScopes()
                ->where('project_id', $project->id)
                ->where('belongs_to_vendor_id', $ownerVendorId)
                ->get();

            foreach ($ownerPayments as $payment) {
                $alreadyExists = Expense::withoutGlobalScopes()
                    ->where('project_id', $project->id)
                    ->where('belongs_to_vendor_id', $registeredVendorId)
                    ->where('vendor_id', $ownerVendorId)
                    ->where('amount', $payment->amount)
                    ->where('date', $payment->date)
                    ->when(! blank($payment->reference), function ($q) use ($payment) {
                        $q->where('invoice', $payment->reference);
                    }, function ($q) {
                        $q->whereNull('invoice');
                    })
                    ->whereNull('deleted_at')
                    ->exists();

                if ($alreadyExists) {
                    continue;
                }

                $isCheck = is_numeric($payment->reference);
                $checkNumber = $isCheck ? (int) $payment->reference : null;
                $checkType = $isCheck ? 'Check' : ($payment->reference ?? 'Other');

                // If no reference but the transaction has a REF number (mobile deposit),
                // extract it and treat as a Check
                if (! $isCheck && blank($payment->reference) && $payment->transaction_id) {
                    $merchantName = \App\Models\Transaction::withoutGlobalScopes()
                        ->where('id', $payment->transaction_id)
                        ->value('plaid_merchant_name');

                    if ($merchantName && preg_match('/REF[:#\s]+(\d+)/i', $merchantName, $matches)) {
                        $isCheck = true;
                        $checkNumber = $matches[1];
                        $checkType = 'Check';
                    }
                }

                // Group expenses under a single check when they share the same check number.
                // Non-numeric references (Other, HD Gift Card, etc.) each get their own check.
                $check = null;
                if ($isCheck && $checkNumber) {
                    $check = Check::withoutGlobalScopes()
                        ->where('check_number', $checkNumber)
                        ->where('vendor_id', $ownerVendorId)
                        ->where('belongs_to_vendor_id', $registeredVendorId)
                        ->whereNull('deleted_at')
                        ->first();

                    if ($check) {
                        $check->update(['amount' => $check->amount + $payment->amount]);
                    }
                }

                if (! $check) {
                    $check = Check::create([
                        'check_type' => $checkType,
                        'check_number' => $checkNumber,
                        'date' => $payment->date,
                        'amount' => $payment->amount,
                        'vendor_id' => $ownerVendorId,
                        'belongs_to_vendor_id' => $registeredVendorId,
                        'created_by_user_id' => 0,
                    ]);
                }

                Expense::create([
                    'date' => $payment->date,
                    'amount' => $payment->amount,
                    'project_id' => $project->id,
                    'vendor_id' => $ownerVendorId,
                    'invoice' => $payment->reference,
                    'check_id' => $check->id,
                    'belongs_to_vendor_id' => $registeredVendorId,
                    'created_by_user_id' => 0,
                ]);
            }
        }
    }

    /**
     * Add project owners to the registering vendor's vendors_vendor so they
     * appear in the vendor's vendor list.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, Project>  $projects
     */
    private function syncVendorsVendor(Vendor $vendor, $projects): void
    {
        // Self-entry so the vendor appears in its own vendor list
        $vendor->vendors()->syncWithoutDetaching([$vendor->id]);

        $ownerVendorIds = $projects
            ->pluck('belongs_to_vendor_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if (! empty($ownerVendorIds)) {
            $vendor->vendors()->syncWithoutDetaching($ownerVendorIds);
        }
    }
}

<?php

namespace App\Services\Expenses;

use App\Models\Check;
use App\Models\Distribution;
use App\Models\Expense;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\Transaction;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The ownership/whitelisting rules an expense save must honour no matter
 * which surface is saving it. This logic used to live only as private
 * methods on App\Livewire\Forms\ExpenseForm (resolveOwnerVendorId(),
 * assertProjectAccessible(), attachCrossVendorRelationships(), called from
 * store()/update()/uploadReceipt()) — moved here so the Hive Contractors
 * app's own create/edit endpoints (Api\App\V1\ExpenseController via
 * AppExpenseDirectory) enforce the exact same rules rather than a
 * re-derived copy. ExpenseForm now delegates to this service; its existing
 * coverage (tests/Feature/Security/T4/ExpenseOwnershipTest.php) is the
 * contract for "exact same rules" and must keep passing.
 */
class ExpenseAccessGuard
{
    public function __construct(private UnmatchedTransactionFinder $unmatchedTransactions)
    {
    }

    /**
     * The "material order" owner vendor is client-chosen, so it must be
     * whitelisted to a vendor the auth tenant can legitimately attribute an
     * expense to: itself, or a vendor already linked to it (VendorScope —
     * the same set the "Belongs To Vendor" dropdown offers). Anything else
     * is refused rather than silently falling back, so a tampered id
     * surfaces as an error instead of quietly mis-attributing.
     */
    public function resolveOwnerVendorId(int $hubVendorId, bool $isMaterialOrder, ?int $belongsToVendorId): int
    {
        if (! $isMaterialOrder || ! $belongsToVendorId) {
            return $hubVendorId;
        }

        if (! Vendor::whereKey($belongsToVendorId)->exists()) {
            throw ValidationException::withMessages([
                'belongs_to_vendor_id' => 'Invalid vendor selected for material order.',
            ]);
        }

        return $belongsToVendorId;
    }

    /**
     * "Attach to Existing Check" (the app's own create form, mirroring
     * ExpenseCreate's existing_check_id) comes from a client-chosen
     * selector — check it against what this tenant can see (CheckScope,
     * which already covers an Admin's whole team or a Member's own checks)
     * rather than trusting the id outright.
     */
    public function assertCheckAccessible(?int $checkId): ?Check
    {
        if (! $checkId) {
            return null;
        }

        $check = Check::query()->whereKey($checkId)->first();

        if (! $check) {
            throw ValidationException::withMessages([
                'existing_check_id' => 'Invalid check selected.',
            ]);
        }

        return $check;
    }

    /**
     * "Create an expense from the transaction" (the app's own Transactions
     * card, mirroring ExpenseCreate::createExpenseFromTransaction()) comes
     * from a client-chosen id — check it against what this tenant's own
     * bank accounts actually hold, and that it is STILL unmatched (someone
     * else's concurrent request, or a reindex-free re-submit, may have
     * already claimed it), rather than trusting the id outright.
     */
    public function assertTransactionAccessible(?int $transactionId, int $vendorId): ?Transaction
    {
        if (! $transactionId) {
            return null;
        }

        $transaction = $this->unmatchedTransactions->find($vendorId, $transactionId);

        if (! $transaction) {
            throw ValidationException::withMessages([
                'transaction_id' => 'This transaction is no longer available.',
            ]);
        }

        return $transaction;
    }

    /**
     * Resolves a project/distribution selector into [projectId, distributionId]
     * — exactly one of the two ever comes back non-null. Accepts either the
     * web's own single-field "D:{id}" encoding (ExpenseForm.php:144-150) via
     * $projectIdRaw, or two already-split fields (the app's own project_id/
     * distribution_id JSON fields) via $distributionIdRaw: an explicit
     * $distributionIdRaw always wins, so callers that already know which
     * field was sent don't need to invent a "D:" string first.
     *
     * @return array{0: ?int, 1: ?int}
     */
    public function resolveProjectSelector(mixed $projectIdRaw, mixed $distributionIdRaw = null): array
    {
        if ($distributionIdRaw !== null && $distributionIdRaw !== '') {
            return [null, (int) $distributionIdRaw];
        }

        if ($projectIdRaw === null || $projectIdRaw === '') {
            return [null, null];
        }

        if (is_string($projectIdRaw) && str_starts_with($projectIdRaw, 'D:')) {
            $distributionId = substr($projectIdRaw, 2);

            return $distributionId === '' ? [null, null] : [null, (int) $distributionId];
        }

        if (is_numeric($projectIdRaw)) {
            $projectId = (int) $projectIdRaw;

            return $projectId > 0 ? [$projectId, null] : [null, null];
        }

        return [null, null];
    }

    /**
     * project_id/distribution_id come from a client-chosen selector — check
     * them against what this tenant can actually see (ProjectScope allows
     * projects shared with an invited sub, so this stays permissive for
     * legitimate collaborators while refusing an id from outside that set).
     */
    public function assertProjectAccessible(?int $projectId, ?int $distributionId): void
    {
        if ($projectId && ! Project::whereKey($projectId)->exists()) {
            throw ValidationException::withMessages([
                'project_id' => 'Invalid project selected.',
            ]);
        }

        if ($distributionId && ! Distribution::whereKey($distributionId)->exists()) {
            throw ValidationException::withMessages([
                'project_id' => 'Invalid distribution selected.',
            ]);
        }
    }

    /**
     * When a material-order expense is attributed to a vendor other than
     * the hub, make that owner vendor's own records reflect it: the
     * merchant vendor gets linked to it, and the project (if any) is
     * invited onto it. Reads the hub vendor off the currently authenticated
     * user, exactly as the original ExpenseForm method did.
     */
    public function attachCrossVendorRelationships(Expense $expense): void
    {
        $hubVendorId = auth()->user()->vendor->id;
        $ownerVendorId = $expense->belongs_to_vendor_id;

        if ((int) $ownerVendorId === (int) $hubVendorId) {
            return;
        }

        // Scoped: resolveOwnerVendorId() already checked this id is either
        // the hub vendor or one already linked to it — never an arbitrary
        // vendor.
        $ownerVendor = Vendor::find($ownerVendorId);
        if (! $ownerVendor) {
            return;
        }

        // Attach the expense vendor (e.g. Home Depot) to the owner's vendor list.
        if ($expense->vendor_id && ! $ownerVendor->vendors()->where('vendor_id', $expense->vendor_id)->exists()) {
            $ownerVendor->vendors()->attach($expense->vendor_id);
        }

        // Attach the project to the owner vendor with Invited status.
        // Scoped: assertProjectAccessible() already checked this project is
        // visible to the auth tenant.
        if ($expense->project_id) {
            $project = Project::find($expense->project_id);

            if ($project && ! $project->vendors()->withoutGlobalScopes()->where('vendor_id', $ownerVendorId)->exists()) {
                $project->vendors()->withoutGlobalScopes()->attach($ownerVendorId, ['client_id' => $project->client_id]);

                if ($project->client_id && ! DB::table('client_vendor')->where('client_id', $project->client_id)->where('vendor_id', $ownerVendorId)->exists()) {
                    DB::table('client_vendor')->insert([
                        'client_id' => $project->client_id,
                        'vendor_id' => $ownerVendorId,
                        'source' => 'auto-attach',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                ProjectStatus::create([
                    'project_id' => $project->id,
                    'belongs_to_vendor_id' => $ownerVendorId,
                    'status_code' => ProjectStatus::getCodeForLabel('Invited') ?? 1,
                    'start_date' => today()->format('Y-m-d'),
                ]);
            }
        }
    }
}

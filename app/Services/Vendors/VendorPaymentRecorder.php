<?php

namespace App\Services\Vendors;

use App\Jobs\SendVendorPaymentEmailJob;
use App\Models\Check;
use App\Models\Expense;
use App\Models\Project;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Validation\ValidationException;

/**
 * Pays a vendor: one Expense per funded project (a Check or an employee's
 * `paid_by` covers them), optionally netted against selected reimbursements
 * the company already paid on the vendor's behalf. Extracted from
 * App\Livewire\Forms\VendorPaymentForm::store() (which now calls here) so
 * the Hive Contractors app's pay screen
 * (App\Http\Controllers\Api\App\V1\VendorPaymentController::store()) writes
 * the exact same Check/Expense rows and queues the exact same
 * payment-notification email.
 *
 * $projectAmounts rides in on caller-controlled input — only positive
 * amounts against a project this tenant can actually see (ProjectScope) are
 * ever written, exactly like VendorPaymentForm::store()'s own loop.
 * $reimbursementExpenseIds is intersected against VendorPaymentOptions'
 * own unsettled set by the caller before this is reached, same as the
 * timesheet payment path.
 */
class VendorPaymentRecorder
{
    /**
     * @param  list<array{id: mixed, amount: mixed}>  $projectAmounts
     * @param  list<int>  $reimbursementExpenseIds
     */
    public function record(
        User $actingUser,
        Vendor $vendor,
        string $date,
        ?string $checkType,
        ?int $checkNumber,
        ?int $bankAccountId,
        ?int $paidBy,
        ?string $invoice,
        array $projectAmounts,
        array $reimbursementExpenseIds,
    ): ?Check {
        // Deductions can only settle through a check — mirrors
        // VendorPaymentForm::store()'s own guard exactly, including the
        // error key the web's form.paid_by field surfaces it under.
        if (! empty($paidBy) && ! empty($reimbursementExpenseIds)) {
            throw ValidationException::withMessages([
                'paid_by' => 'Reimbursement deductions can only be settled by a check — clear Paid By or unselect the reimbursements.',
            ]);
        }

        $check = empty($paidBy) ? Check::create([
            'check_type' => $checkType,
            'check_number' => $checkNumber,
            'date' => $date,
            'bank_account_id' => $bankAccountId,
            'vendor_id' => $vendor->id,
            'belongs_to_vendor_id' => $actingUser->vendor->id,
            'created_by_user_id' => $actingUser->id,
        ]) : null;

        foreach ($projectAmounts as $project) {
            $amount = $project['amount'] ?? null;
            if (! is_numeric($amount) || $amount <= 0) {
                continue;
            }

            if (! Project::query()->whereKey($project['id'] ?? null)->exists()) {
                continue;
            }

            Expense::create([
                'amount' => $amount,
                'date' => $date,
                'invoice' => $check ? null : $invoice,
                'project_id' => $project['id'],
                'vendor_id' => $vendor->id,
                'check_id' => $check?->id,
                'paid_by' => $check?->id ? null : $paidBy,
                'belongs_to_vendor_id' => $actingUser->vendor->id,
                'created_by_user_id' => $actingUser->id,
            ]);
        }

        if ($check) {
            Expense::whereIn('id', $reimbursementExpenseIds)
                ->where('reimbursment', 'V:'.$vendor->id)
                ->whereNull('check_id')
                ->get()
                ->each(function (Expense $expense) use ($check): void {
                    $expense->check_id = $check->id;
                    $expense->save();
                });

            // Check total = project expenses − attached vendor
            // reimbursements (centralized in Check::recalculateAmount).
            $check->recalculateAmount();

            $this->queuePaymentEmail($actingUser, $vendor, $check);
        }

        return $check;
    }

    /**
     * A payment made outside business hours shouldn't email the sub at
     * 9 PM — held until the paying company's next opening, same as
     * VendorPaymentCreate::save().
     */
    private function queuePaymentEmail(User $actingUser, Vendor $vendor, Check $check): void
    {
        $sendAt = $actingUser->vendor->nextBusinessHoursOpening();

        SendVendorPaymentEmailJob::dispatch($actingUser, $vendor, $check)->delay($sendAt);
    }
}

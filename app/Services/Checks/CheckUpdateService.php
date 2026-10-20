<?php

namespace App\Services\Checks;

use App\Models\Check;
use App\Models\Expense;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Editing a check's own fields (bank account, check type/number, payee,
 * date) — extracted from App\Livewire\Forms\CheckForm so the Hive
 * Contractors app's CheckController writes identically, including the
 * cascading side effects: a new date moves every expense the check dated
 * (Check::moveDate()), and a new payee reassigns that same vendor onto the
 * expenses it paid.
 */
class CheckUpdateService
{
    /**
     * Validation rules for editing $check with a given bank account —
     * mirrors CheckForm::rules() exactly (the same five keys; check_type
     * has no rule there either, despite the #[Validate] attribute on the
     * property, since a Form's own rules() method replaces it).
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(Check $check, int $companyVendorId, ?int $bankAccountId): array
    {
        return [
            'bank_account_id' => [
                'required',
                Rule::exists('bank_accounts', 'id')->where('vendor_id', $companyVendorId),
            ],
            'user_id' => [
                'nullable',
                Rule::exists('user_vendor', 'user_id')->where('vendor_id', $companyVendorId)->where('is_employed', 1),
            ],
            'vendor_id' => ['nullable', 'exists:vendors,id'],
            // A check cannot clear before it was written: when the bank has
            // already matched it, the date may not be later than that.
            'date' => [
                'required',
                'date',
                'before_or_equal:'.($this->latestAllowedDate($check) ?? 'today'),
                'after:2017-01-01',
            ],
            'check_number' => [
                'required_if:check_type,Check',
                'nullable',
                'numeric',
                Rule::unique('checks', 'check_number')
                    ->where(fn ($query) => $query->whereNull('deleted_at')->where('bank_account_id', $bankAccountId))
                    ->ignore($check),
            ],
        ];
    }

    /** The latest date the check may carry: the day the bank cleared it, when it has. */
    public function latestAllowedDate(Check $check): ?string
    {
        $cleared = $check->transactions()->min('transaction_date');

        return $cleared ? Carbon::parse($cleared)->toDateString() : null;
    }

    /**
     * Applies a validated edit to $check — mirrors CheckForm::update()'s
     * write exactly: field assignment, moving the expenses the check dated
     * when the date changes, and reassigning the vendor on expenses this
     * check paid when the payee changes.
     *
     * @param  array{bank_account_id: int, check_type: ?string, check_number: int|string|null, vendor_id: ?int, user_id: ?int, date: string}  $attributes
     */
    public function update(Check $check, array $attributes): Check
    {
        $oldVendorId = $check->vendor_id;

        $updateData = [
            'bank_account_id' => $attributes['bank_account_id'],
            'check_type' => $attributes['check_type'] ?? null,
            'check_number' => $attributes['check_number'] ?? null,
            'vendor_id' => $attributes['vendor_id'] ?? null,
        ];

        // user_id (the employee a payroll check was written to) is never
        // actually editable through either edit form — CheckForm::
        // setCheck()/update() only ever round-trips the SAME value, it just
        // always includes the key explicitly. Only write it when the
        // caller's payload genuinely contains the key, so a client that
        // omits it (the app used to) can never silently null out a payroll
        // check's employee.
        if (array_key_exists('user_id', $attributes)) {
            $updateData['user_id'] = $attributes['user_id'];
        }

        $check->update($updateData);

        if (! empty($attributes['date'])) {
            $check->moveDate($attributes['date']);
        }

        if (! empty($attributes['vendor_id']) && $oldVendorId !== (int) $attributes['vendor_id']) {
            // Direct FK expenses
            Expense::where('check_id', $check->id)
                ->where('vendor_id', $oldVendorId)
                ->update(['vendor_id' => $attributes['vendor_id']]);

            // Pivot expenses
            $pivotExpenseIds = $check->expensesMany()->pluck('expenses.id');
            if ($pivotExpenseIds->isNotEmpty()) {
                Expense::whereIn('id', $pivotExpenseIds)
                    ->where('vendor_id', $oldVendorId)
                    ->update(['vendor_id' => $attributes['vendor_id']]);
            }
        }

        return $check;
    }

    /** Removes $check — mirrors CheckForm::delete(). Authorization is the caller's job. */
    public function remove(Check $check): void
    {
        $check->delete();
    }
}
